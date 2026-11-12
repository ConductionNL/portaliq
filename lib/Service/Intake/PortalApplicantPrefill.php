<?php

/**
 * Portaliq Portal Applicant Prefill
 *
 * The applicant block on a form, filled from the identity that is signed in
 * and from nothing else.
 *
 * The rule that matters here is what happens when there is no session: every
 * applicant field comes back empty, and nothing in the answer hints that a
 * value existed. A form that said "we know your address, sign in to use it"
 * would leak that the visitor is known, which is exactly what an anonymous
 * intake form must not do.
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
 * @spec openspec/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\PortalAccountService;

/**
 * Fills the applicant block from the signed-in identity's own claims.
 *
 * @spec openspec/specs/portal-intake-form/spec.md
 */
class PortalApplicantPrefill {
	/**
	 * The applicant fields that may be prefilled, and the account field each
	 * one is taken from. Nothing outside this map is ever prefilled, so adding
	 * a field to a form cannot start disclosing a new part of the account.
	 */
	private const APPLICANT_FIELDS = [
		'applicantName' => 'displayName',
		'applicantEmail' => 'email',
	];

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Reads the signed-in account.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
	) {
	}//end __construct()

	/**
	 * The prefill for one render.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 *
	 * @return array<string, mixed> Field name to value. Empty for a visitor
	 *         with no session.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	public function forSubject(?array $subject, array $fields): array {
		$account = $this->ownAccount(subject: $subject);
		if ($account === null) {
			return [];
		}

		$prefill = [];
		foreach (array_filter($fields, 'is_array') as $field) {
			$prefill += $this->valueFor(name: (string)($field['name'] ?? ''), account: $account);
		}

		return $prefill;
	}//end forSubject()

	/**
	 * The account the session names, or null for a visitor without one.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownAccount(?array $subject): ?array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($subjectRef === '') {
			return null;
		}

		return $this->accountOf(subjectRef: $subjectRef);
	}//end ownAccount()

	/**
	 * The account of a subject reference, only when the account the reader
	 * answered is the one asked for.
	 *
	 * Defence in depth: the account the reader answered must be the one the
	 * session names. Prefilling from any other account would put somebody
	 * else's name on this citizen's form.
	 *
	 * @param string $subjectRef The session's subject reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function accountOf(string $subjectRef): ?array {
		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || (string)($account['subjectRef'] ?? '') !== $subjectRef) {
			return null;
		}

		return $account;
	}//end accountOf()

	/**
	 * The prefill of one applicant field from the account, or [] when the
	 * field is no applicant field or the account holds nothing for it.
	 *
	 * @param string               $name    The field's name.
	 * @param array<string, mixed> $account The signed-in account.
	 *
	 * @return array<string, string>
	 */
	private function valueFor(string $name, array $account): array {
		$source = (self::APPLICANT_FIELDS[$name] ?? null);
		if ($source === null) {
			return [];
		}

		$value = (string)($account[$source] ?? '');
		if ($value === '') {
			return [];
		}

		return [$name => $value];
	}//end valueFor()
}//end class
