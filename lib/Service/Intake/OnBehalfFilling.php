<?php

/**
 * Portaliq On Behalf Filling
 *
 * An employee at the desk fills a form in for a resident or a company. Who
 * may do so, who the applicant is, and what the registers say about them.
 *
 * The employee is a Nextcloud user in the portal's desk group
 * (`authentication.deskGroup`), and the form must allow it
 * (`staffMayFill`). Both are checked every time, never remembered: a user
 * taken out of the group cannot finish a form they started.
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalRegisteredDetailsServiceInterface;
use OCA\Portaliq\Service\AuditTrailService;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The desk employee, the applicant they fill in for, and the register lookup.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
 */
class OnBehalfFilling {

	/**
	 * The provider a desk session carries, telling it from a resident's.
	 */
	public const PROVIDER = 'desk';

	/**
	 * The audience of a desk session.
	 */
	public const AUDIENCE = 'desk';

	/**
	 * The audit verb of a BSN or KvK lookup by an employee.
	 */
	public const LOOKUP_VERB = 'desk.lookup';

	/**
	 * The longest applicant name an employee may note.
	 */
	private const NAME_MAX = 200;

	/**
	 * The form fields a person record may fill, and the record value each takes.
	 */
	private const PERSON_FIELDS = [
		'applicantName' => 'name',
		'applicantBirthDate' => 'birthDate',
		'applicantStreet' => 'address.street',
		'applicantHouseNumber' => 'address.number',
		'applicantPostcode' => 'address.postcode',
		'applicantCity' => 'address.city',
	];

	/**
	 * The form fields a company record may fill, and the record value each takes.
	 */
	private const COMPANY_FIELDS = [
		'applicantName' => 'tradeName',
		'applicantKvk' => 'kvkNumber',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager                           $groups  Tells whether a user is in the desk group.
	 * @param IUserManager                            $users   Reads the employee's display name.
	 * @param PortalRegisteredDetailsServiceInterface $details Looks the BSN up in the BRP and the KvK number in the KvK.
	 * @param AuditTrailService                       $audit   Writes the audit line of every lookup.
	 * @param DutchFormats                            $formats Checks a BSN and a KvK number.
	 */
	public function __construct(
		private readonly IGroupManager $groups,
		private readonly IUserManager $users,
		private readonly PortalRegisteredDetailsServiceInterface $details,
		private readonly AuditTrailService $audit,
		private readonly DutchFormats $formats = new DutchFormats(),
	) {
	}//end __construct()

	/**
	 * Whether the form allows an employee to fill it in.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function offered(array $binding): bool {
		return ($binding['staffMayFill'] ?? false) === true;
	}//end offered()

	/**
	 * The portal's desk group, or '' when it names none.
	 *
	 * @param array<string, mixed> $site The portal.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function deskGroup(array $site): string {
		$group = ($site['authentication']['deskGroup'] ?? '');
		if (is_string($group) === false) {
			return '';
		}

		return trim($group);
	}//end deskGroup()

	/**
	 * The employee, when this Nextcloud user may fill this form in on this
	 * portal: the form allows it, the portal names a desk group, and the user
	 * is in it. Null otherwise, so a portal without a desk group has no staff
	 * mode at all.
	 *
	 * @param array<string, mixed> $site    The portal.
	 * @param array<string, mixed> $binding The form binding.
	 * @param string               $uid     The Nextcloud user id.
	 *
	 * @return array{uid: string, displayName: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function employee(array $site, array $binding, string $uid): ?array {
		$group = $this->deskGroup(site: $site);
		if ($uid === '' || $group === '' || $this->offered(binding: $binding) === false) {
			return null;
		}

		if ($this->groups->isInGroup($uid, $group) === false) {
			return null;
		}

		$user = $this->users->get($uid);
		$name = $uid;
		if ($user !== null && $user->getDisplayName() !== '') {
			$name = $user->getDisplayName();
		}

		return ['uid' => $uid, 'displayName' => $name];
	}//end employee()

	/**
	 * The applicant an employee named, normalised, or null when it is not one:
	 * a BSN that passes the elfproef, an 8-digit KvK number, or a name of at
	 * most 200 characters for an employee who fills it in under their own name.
	 *
	 * @param mixed $raw The request's `onBehalf`: `{kind, value}`.
	 *
	 * @return array{kind: string, value: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function applicant(mixed $raw): ?array {
		if (is_array($raw) === false || is_string($raw['kind'] ?? null) === false || is_string($raw['value'] ?? null) === false) {
			return null;
		}

		$kind  = $raw['kind'];
		$value = trim($raw['value']);
		$clean = match ($kind) {
			'bsn' => $this->formats->normalise(format: 'bsn', value: $value),
			'kvk' => $this->formats->normalise(format: 'kvk', value: $value),
			'named' => $this->name(value: $value),
			default => null,
		};
		if ($clean === null) {
			return null;
		}

		return ['kind' => $kind, 'value' => $clean];
	}//end applicant()

	/**
	 * Look the applicant up and prefill the form's applicant fields.
	 *
	 * A BSN is read from the BRP, a KvK number from the KvK, each through
	 * OpenRegister's providers, and every such lookup writes an audit line
	 * naming the employee and the number. A named applicant is not looked up.
	 *
	 * @param array<string, mixed>             $site      The portal.
	 * @param array{uid: string, displayName: string} $employee The employee.
	 * @param array{kind: string, value: string} $applicant The applicant.
	 * @param array<int, array<string, mixed>> $fields    The form's fields.
	 *
	 * @return array{available: bool, prefill: array<string, string>, reason: string}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function lookUp(array $site, array $employee, array $applicant, array $fields): array {
		if ($applicant['kind'] === 'named') {
			$named = $this->fill(fields: $fields, map: ['applicantName' => 'name'], record: ['name' => $applicant['value']]);
			return ['available' => true, 'prefill' => $named, 'reason' => ''];
		}

		$register = 'kvk';
		if ($applicant['kind'] === 'bsn') {
			$register = 'brp';
		}

		$this->audit->record(
			verb: self::LOOKUP_VERB,
			subjectRef: $employee['uid'],
			organisation: (string)($site['organisation'] ?? ''),
			register: $register,
			schema: $applicant['kind'],
			id: '',
			detail: ['employee' => $employee['uid'], 'kind' => $applicant['kind'], 'value' => $applicant['value']]
		);

		if ($applicant['kind'] === 'bsn') {
			$answer = $this->details->forBsn(bsn: $applicant['value']);
			$record = (array)($answer['person'] ?? []);
			$map    = self::PERSON_FIELDS;
		} else {
			$answer = $this->details->forKvkNumber(kvkNumber: $applicant['value']);
			$record = (array)($answer['company'] ?? []);
			$map    = self::COMPANY_FIELDS;
		}

		if (($answer['available'] ?? false) !== true) {
			return ['available' => false, 'prefill' => [], 'reason' => (string)($answer['reason'] ?? 'source_unavailable')];
		}

		return ['available' => true, 'prefill' => $this->fill(fields: $fields, map: $map, record: $record), 'reason' => ''];
	}//end lookUp()

	/**
	 * What the server records about who filled the form in, for the
	 * submission and the delivery: `filledBy` and `applicant`.
	 *
	 * @param array{uid: string, displayName: string} $employee  The employee.
	 * @param array{kind: string, value: string}      $applicant The applicant.
	 *
	 * @return array{filledBy: array{uid: string, displayName: string}, applicant: array{kind: string, value: string}}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function serverValues(array $employee, array $applicant): array {
		return [
			'filledBy' => ['uid' => $employee['uid'], 'displayName' => $employee['displayName']],
			'applicant' => ['kind' => $applicant['kind'], 'value' => $applicant['value']],
		];
	}//end serverValues()

	/**
	 * A noted applicant name, or null when it is empty or too long.
	 *
	 * @param string $value The trimmed name.
	 *
	 * @return string|null
	 */
	private function name(string $value): ?string {
		if ($value === '' || mb_strlen($value) > self::NAME_MAX) {
			return null;
		}

		return $value;
	}//end name()

	/**
	 * The prefill of the form's fields that the map names, from the record.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, string>            $map    Field name to record path (dots for nesting).
	 * @param array<string, mixed>             $record The register record.
	 *
	 * @return array<string, string>
	 */
	private function fill(array $fields, array $map, array $record): array {
		$prefill = [];
		foreach (array_filter($fields, 'is_array') as $field) {
			$name = (string)($field['name'] ?? '');
			$path = ($map[$name] ?? null);
			if ($path === null) {
				continue;
			}

			$value = $record;
			foreach (explode('.', $path) as $part) {
				$value = $this->part(value: $value, key: $part);
			}

			if (is_scalar($value) === true && (string)$value !== '') {
				$prefill[$name] = (string)$value;
			}
		}

		return $prefill;
	}//end fill()

	/**
	 * One key of a nested record value, or null when the value is not an array.
	 *
	 * @param mixed  $value The value.
	 * @param string $key   The key.
	 *
	 * @return mixed
	 */
	private function part(mixed $value, string $key): mixed {
		if (is_array($value) === false) {
			return null;
		}

		return ($value[$key] ?? null);
	}//end part()
}//end class
