<?php

/**
 * Portaliq Cosign Invitation
 *
 * A form that needs a second signer creates its case at once, with what the
 * case waits on, and the case app holds that wait (decision 271). This class
 * builds that entry from the binding's `cosign` settings and the answers,
 * and signs the link the co-signer gets: a stateless token over the case, the
 * kind, the due date and a hash of the applicant's subject, so the portal
 * keeps no queue (decisions 179 and 181).
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCP\Security\ICrypto;

/**
 * The co-sign entry on the case, and the signed link to it.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class CosignInvitation {
	/**
	 * The kind used when the binding names none.
	 */
	public const DEFAULT_KIND = 'cosign';

	/**
	 * The days the co-signer has when the binding names none.
	 */
	public const DEFAULT_DAYS = 14;

	/**
	 * The server value the destination case declares.
	 */
	public const SERVER_FIELD = 'intakeObligations';

	/**
	 * The zone a due date is a day in.
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Signs the link with the instance secret.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * The binding's co-sign settings, or null when it needs no co-signer.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array{kind: string, title: string, days: int, nameField: string, emailField: string, endpoint: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function settings(array $binding): ?array {
		$cosign = $binding['cosign'] ?? null;
		if (is_array($cosign) === false || ($cosign['required'] ?? false) !== true) {
			return null;
		}

		$days = (int)($cosign['deadlineDays'] ?? self::DEFAULT_DAYS);
		$kind = trim((string)($cosign['kind'] ?? ''));
		if ($kind === '') {
			$kind = self::DEFAULT_KIND;
		}

		return [
			'kind' => $kind,
			'title' => trim((string)($cosign['title'] ?? '')),
			'days' => max(1, $days),
			'nameField' => trim((string)($cosign['nameField'] ?? '')),
			'emailField' => trim((string)($cosign['emailField'] ?? '')),
			'endpoint' => trim((string)($cosign['endpoint'] ?? '')),
		];
	}//end settings()

	/**
	 * The entry the case is created with, or the name of the field that is missing.
	 *
	 * @param array<string, mixed> $settings What settings() answered.
	 * @param array<string, mixed> $answers  The checked answers.
	 * @param int|null             $now      The unix second, for tests.
	 *
	 * @return array<string, string>|string The entry, or the field name to refuse.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function entry(array $settings, array $answers, ?int $now = null): array|string {
		$name  = trim((string)($answers[$settings['nameField']] ?? ''));
		$email = trim((string)($answers[$settings['emailField']] ?? ''));
		if ($name === '') {
			return $this->fieldOrForm(field: $settings['nameField']);
		}

		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return $this->fieldOrForm(field: $settings['emailField']);
		}

		$today = (new DateTimeImmutable('@'.($now ?? time())))->setTimezone(new DateTimeZone(self::ZONE));

		return [
			'kind' => $settings['kind'],
			'title' => $settings['title'],
			'holderName' => $name,
			'holderEmail' => $email,
			'dueAt' => $today->modify('+'.(int)$settings['days'].' days')->format('Y-m-d'),
			'state' => 'awaiting',
		];
	}//end entry()

	/**
	 * The signed token for the co-signer's link.
	 *
	 * @param array<string, string> $claims portal, route, case, reference, kind, dueAt, holderName, title, applicant (the applicant's subject).
	 *
	 * @return string `<payload>.<signature>`, URL safe.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function token(array $claims): string {
		$claims['applicant'] = $this->subjectHash(subjectRef: (string)($claims['applicant'] ?? ''));
		$payload = rtrim(strtr(base64_encode((string)json_encode($claims)), '+/', '-_'), '=');

		return $payload.'.'.$this->crypto->calculateHMAC('cosign:'.$payload);
	}//end token()

	/**
	 * The claims of a token this instance signed, or null.
	 *
	 * @param string $token The token from the link.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function read(string $token): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2 || strlen($token) > 4096) {
			return null;
		}

		if (hash_equals($this->crypto->calculateHMAC('cosign:'.$parts[0]), $parts[1]) === false) {
			return null;
		}

		$claims = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
		if (is_array($claims) === false) {
			return null;
		}

		return array_map(static fn ($value): string => (string)$value, $claims);
	}//end read()

	/**
	 * Whether the token's last day has passed.
	 *
	 * @param array<string, string> $claims The claims.
	 * @param int|null              $now    The unix second, for tests.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function isPast(array $claims, ?int $now = null): bool {
		$today = (new DateTimeImmutable('@'.($now ?? time())))->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');

		return (($claims['dueAt'] ?? '') === '' || $claims['dueAt'] < $today);
	}//end isPast()

	/**
	 * Whether the signed-in subject is the applicant the token names.
	 *
	 * @param array<string, string> $claims     The claims.
	 * @param string                $subjectRef The signed-in subject.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function isApplicant(array $claims, string $subjectRef): bool {
		return hash_equals((string)($claims['applicant'] ?? ''), $this->subjectHash(subjectRef: $subjectRef));
	}//end isApplicant()

	/**
	 * The field a refusal is filed under: the field, or the form when the binding names none.
	 *
	 * @param string $field The field name, or ''.
	 *
	 * @return string
	 */
	private function fieldOrForm(string $field): string {
		if ($field === '') {
			return DestinationMapping::FORM_LEVEL;
		}

		return $field;
	}//end fieldOrForm()

	/**
	 * A one-way hash of a subject, so the link carries no subject reference.
	 *
	 * @param string $subjectRef The subject.
	 *
	 * @return string
	 */
	private function subjectHash(string $subjectRef): string {
		return hash('sha256', 'cosign-subject:'.$subjectRef);
	}//end subjectHash()
}//end class
