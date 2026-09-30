<?php

/**
 * Portaliq Portal Registered Details Service
 *
 * What the base registrations hold about the signed-in person or company
 * (identity-registered-details). The identifier comes from the caller's own
 * portalAccount, never from the request. The lookup runs through
 * OpenRegister's BrpPersonProvider or KvkProvider in process; portaliq holds
 * no URL, token or certificate of its own. Nothing is stored.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalAccountService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the caller's own BRP or KvK record.
 */
class PortalRegisteredDetailsService {

	/**
	 * OpenRegister's person lookup (integration-brp-haalcentraal).
	 */
	private const PERSON_PROVIDER = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\BrpPersonProvider';

	/**
	 * OpenRegister's company lookup (integration-kvk-opencorporates).
	 */
	private const COMPANY_PROVIDER = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\KvkProvider';

	/**
	 * The identity types whose reference may be a BSN.
	 */
	private const PERSON_TYPES = ['digid', 'eidas'];

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts  Finds the caller's account.
	 * @param ContainerInterface   $container Resolves OpenRegister's providers when installed.
	 * @param LoggerInterface      $logger    Names the cause of an unavailable source.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The registered details of the account behind one subject.
	 *
	 * @param string $subjectRef The caller's subject, from the bearer.
	 *
	 * @return array<string, mixed> `{available: true, kind, person|company}` or
	 *                              `{available: false, reason}` with reason one of
	 *                              no_account, no_registration_identifier,
	 *                              source_unavailable, not_found.
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
	 */
	public function forSubject(string $subjectRef): array {
		$account = null;
		if ($subjectRef !== '') {
			$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		}

		if ($account === null || ($account['status'] ?? '') === 'removed') {
			return self::refusal(reason: 'no_account');
		}

		$reference = trim((string)($account['identityRef'] ?? ''));
		$kind      = $this->kindOf(identityType: (string)($account['identityType'] ?? ''), reference: $reference);
		if ($kind === 'person') {
			return $this->person(bsn: $reference);
		}

		if ($kind === 'company') {
			return $this->company(kvkNumber: $reference);
		}

		return self::refusal(reason: 'no_registration_identifier');
	}//end forSubject()

	/**
	 * The branch numbers the KvK holds for one company, for the branch choice
	 * of signin-eherkenning-branch.
	 *
	 * @param string $kvkNumber The 8-digit KvK number.
	 *
	 * @return array<int, string>|null The branch numbers, or null when the
	 *                                 number is not a KvK number or the
	 *                                 source cannot answer.
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-business-user-sees-their-companys-kvk-record-req-ird-002
	 */
	public function companyBranchNumbers(string $kvkNumber): ?array {
		if (preg_match('/^\d{8}$/', $kvkNumber) !== 1) {
			return null;
		}

		$answer = $this->company(kvkNumber: $kvkNumber);
		if (($answer['available'] ?? false) !== true) {
			return null;
		}

		return array_column($answer['company']['branches'], 'number');
	}//end companyBranchNumbers()

	/**
	 * Which record an account can be looked up in.
	 *
	 * @param string $identityType The account's identity type.
	 * @param string $reference    The account's identity reference.
	 *
	 * @return string person, company or none.
	 */
	private function kindOf(string $identityType, string $reference): string {
		if (in_array($identityType, self::PERSON_TYPES, true) === true && self::isBsn(value: $reference) === true) {
			return 'person';
		}

		if ($identityType === 'eherkenning' && preg_match('/^\d{8}$/', $reference) === 1) {
			return 'company';
		}

		return 'none';
	}//end kindOf()

	/**
	 * The person record for one BSN.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array<string, mixed>
	 */
	private function person(string $bsn): array {
		$answer = $this->lookup(class: self::PERSON_PROVIDER, method: 'lookupByBsn', identifier: $bsn);
		if ($answer === null) {
			return self::refusal(reason: 'source_unavailable');
		}

		$first = ($answer['results'][0] ?? null);
		if (is_array($first) === false) {
			return self::refusal(reason: 'not_found');
		}

		return ['available' => true, 'kind' => 'person', 'person' => (new RegisteredDetailsShape())->person(raw: $first)];
	}//end person()

	/**
	 * The company record for one KvK number.
	 *
	 * @param string $kvkNumber The KvK number.
	 *
	 * @return array<string, mixed>
	 */
	private function company(string $kvkNumber): array {
		$answer = $this->lookup(class: self::COMPANY_PROVIDER, method: 'lookupByKvkNumber', identifier: $kvkNumber);
		if ($answer === null) {
			return self::refusal(reason: 'source_unavailable');
		}

		$rows = [];
		foreach ((array)($answer['results'] ?? []) as $row) {
			if (is_array($row) === true && (string)($row['kvkNummer'] ?? $kvkNumber) === $kvkNumber) {
				$rows[] = $row;
			}
		}

		if ($rows === []) {
			return self::refusal(reason: 'not_found');
		}

		return ['available' => true, 'kind' => 'company', 'company' => (new RegisteredDetailsShape())->company(rows: $rows, kvkNumber: $kvkNumber)];
	}//end company()

	/**
	 * Call one OpenRegister provider, or null when it is missing or reports
	 * the source unavailable. The log names the provider and the cause, never
	 * the identifier.
	 *
	 * @param string $class      The provider class.
	 * @param string $method     The lookup method.
	 * @param string $identifier The BSN or KvK number.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lookup(string $class, string $method, string $identifier): ?array {
		$cause  = 'provider_missing';
		$answer = null;
		try {
			$provider = $this->container->get($class);
			if (is_object($provider) === true && method_exists($provider, $method) === true) {
				$answer = (array)$provider->{$method}($identifier);
				$cause  = (string)($answer['cause'] ?? 'unavailable');
			}
		} catch (Throwable $failure) {
			$answer = null;
			$cause  = 'provider_missing';
		}

		if ($answer !== null && ($answer['unavailable'] ?? false) !== true) {
			return $answer;
		}

		$this->logger->warning(
			'Portaliq: registered details cannot be read',
			['provider' => substr($class, (int)strrpos($class, '\\') + 1), 'cause' => $cause]
		);
		return null;
	}//end lookup()

	/**
	 * Whether a value is a BSN: nine digits passing the eleven test, the same
	 * rule as OpenRegister's BsnFormat (ADR-008 rule 4), all zeros refused.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isBsn(string $value): bool {
		if (preg_match('/^\d{9}$/', $value) !== 1 || $value === '000000000') {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += (int)$value[$i] * (9 - $i);
		}

		$sum -= (int)$value[8];
		return ($sum % 11) === 0;
	}//end isBsn()

	/**
	 * A "nothing to show" answer.
	 *
	 * @param string $reason Why.
	 *
	 * @return array{available: false, reason: string}
	 */
	private static function refusal(string $reason): array {
		return ['available' => false, 'reason' => $reason];
	}//end refusal()
}//end class
