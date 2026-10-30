<?php

/**
 * Portaliq Staff Fill
 *
 * An employee fills a form in for a resident or a company, at the desk or on
 * the phone (resident-identity-in-forms REQ-RIF-005). Decides whether the
 * signed-in Nextcloud user may do so on this form, checks the applicant they
 * name, fills the applicant block from the BRP or the KvK and writes the
 * audit line every such lookup owes.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Staff mode on a form: who may use it, for whom, and what is filled in.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
class StaffFill {
	/**
	 * The applicant kinds an employee can continue as.
	 */
	public const KINDS = ['bsn', 'kvk', 'named'];

	/**
	 * The audit verb of a BRP or KvK lookup made by an employee.
	 */
	public const AUDIT_VERB = 'staff-lookup';

	/**
	 * The register and schema an audit line names, per applicant kind.
	 */
	private const LOOKUP_TARGETS = [
		'bsn' => ['register' => 'brp', 'schema' => 'person'],
		'kvk' => ['register' => 'kvk', 'schema' => 'company'],
	];

	/**
	 * The longest applicant name an employee may note.
	 */
	private const NAME_MAX = 200;

	/**
	 * Constructor.
	 *
	 * @param IUserSession                   $userSession The signed-in Nextcloud user.
	 * @param IGroupManager                  $groups      Answers whether that user is in the desk group.
	 * @param PortalAccountLookup            $accounts    Tells a resident's own account from an employee, and finds the applicant's account.
	 * @param PortalRegisteredDetailsService $registers   Reads the BRP and the KvK.
	 * @param DutchFormats                   $formats     Checks the BSN and the KvK number.
	 * @param AuditTrailService              $audit       Records each lookup.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly PortalAccountLookup $accounts,
		private readonly PortalRegisteredDetailsService $registers,
		private readonly DutchFormats $formats,
		private readonly AuditTrailService $audit,
	) {
	}//end __construct()

	/**
	 * The employee who may fill this form in, or null.
	 *
	 * Null unless the binding allows staff, the portal names a desk group,
	 * a Nextcloud user is signed in, that user is in the desk group and is not
	 * a resident with a portal account of their own.
	 *
	 * @param array<string, mixed> $site    The portal.
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return array{uid: string, displayName: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function employee(array $site, array $binding): ?array {
		if (($binding['staffMayFill'] ?? false) !== true) {
			return null;
		}

		$group = trim((string)(($site['authentication'] ?? [])['deskGroup'] ?? ''));
		$user  = $this->userSession->getUser();
		if ($group === '' || $user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->groups->isInGroup($uid, $group) === false) {
			return null;
		}

		if ($this->accounts->bySubjectRef(subjectRef: $uid) !== null) {
			return null;
		}

		return ['uid' => $uid, 'displayName' => $user->getDisplayName()];
	}//end employee()

	/**
	 * The applicant as the employee named them, checked and normalised, or null.
	 *
	 * @param array<string, mixed> $input `kind` (bsn, kvk or named) and `value`.
	 *
	 * @return array{kind: string, value: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function applicant(array $input): ?array {
		$kind  = (string)($input['kind'] ?? '');
		$value = trim((string)($input['value'] ?? ''));
		if (in_array($kind, self::KINDS, true) === false || $value === '') {
			return null;
		}

		if ($kind === 'named') {
			$name = (string)preg_replace('/\s+/u', ' ', $value);
			if (mb_strlen($name) > self::NAME_MAX) {
				return null;
			}

			return ['kind' => 'named', 'value' => $name];
		}

		// Spaces and dots are how a number is read out at the desk, not part of it.
		$normalised = $this->formats->normalise(format: $kind, value: (string)preg_replace('/[\s.]/u', '', $value));
		if ($normalised === null) {
			return null;
		}

		return ['kind' => $kind, 'value' => $normalised];
	}//end applicant()

	/**
	 * The applicant block filled in for the applicant. A BSN is looked up in
	 * the BRP and a KvK number in the KvK; each lookup writes an audit line
	 * naming the employee, also when the register cannot answer.
	 *
	 * @param array{uid: string, displayName: string} $employee  The employee.
	 * @param array{kind: string, value: string}      $applicant The applicant.
	 * @param array<string, mixed>                    $site      The portal.
	 *
	 * @return array<string, string> Field name to value; empty when nothing was found.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function prefill(array $employee, array $applicant, array $site): array {
		if ($applicant['kind'] === 'named') {
			return ['applicantName' => $applicant['value']];
		}

		$this->audit->record(
			verb: self::AUDIT_VERB,
			subjectRef: $employee['uid'],
			organisation: (string)($site['organisation'] ?? ''),
			register: self::LOOKUP_TARGETS[$applicant['kind']]['register'],
			schema: self::LOOKUP_TARGETS[$applicant['kind']]['schema'],
			id: '',
			detail: ['employee' => $employee['uid'], 'kind' => $applicant['kind'], 'value' => $applicant['value']]
		);

		$name = $this->registeredName(applicant: $applicant);
		if ($name === '') {
			return [];
		}

		return ['applicantName' => $name];
	}//end prefill()

	/**
	 * The applicant's name as the BRP or the KvK holds it, or ''.
	 *
	 * @param array{kind: string, value: string} $applicant A BSN or KvK applicant.
	 *
	 * @return string
	 */
	private function registeredName(array $applicant): string {
		if ($applicant['kind'] === 'bsn') {
			$found = $this->registers->forPerson(bsn: $applicant['value']);
			return (string)(($found['person'] ?? [])['name'] ?? '');
		}

		$found = $this->registers->forCompany(kvkNumber: $applicant['value']);
		return (string)(($found['company'] ?? [])['tradeName'] ?? '');
	}//end registeredName()

	/**
	 * The subject reference the request is filed under: the resident's own
	 * portal account for a BSN, so it shows in their Mijn zaken, else none.
	 *
	 * @param array{kind: string, value: string} $applicant The applicant.
	 *
	 * @return string The subject reference, or ''.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function subjectRefFor(array $applicant): string {
		if ($applicant['kind'] !== 'bsn') {
			return '';
		}

		return (string)($this->accounts->activeByCitizenNumber(bsn: $applicant['value'])['subjectRef'] ?? '');
	}//end subjectRefFor()
}//end class
