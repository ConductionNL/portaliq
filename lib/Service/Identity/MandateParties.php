<?php

/**
 * Portaliq Mandate Parties (site-mandates-the-represented-manage)
 *
 * The parties a session manages or holds a mandate as.
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
 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d1-who-is-the-represented-party
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * Who a session is as a mandate party: the party it manages, the parties it
 * holds a mandate as, and a stored party in its typed form.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d1-who-is-the-represented-party
 */
class MandateParties {
	/**
	 * The party a session may manage, from the session alone: the company of
	 * an eHerkenning sign-in, the person of any other. An eHerkenning session
	 * that does not carry its company's number manages nothing.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return string|null `kvk:<8 digits>`, `subject:<ref>`, or null.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d1-who-is-the-represented-party
	 */
	public function partyOf(array $subject): ?string {
		if (($subject['provider'] ?? '') === 'eherkenning') {
			$kvk = (string)($subject['kvk'] ?? '');
			if (preg_match('/^\d{8}$/', $kvk) === 1) {
				return 'kvk:' . $kvk;
			}

			return null;
		}

		$ref = (string)($subject['subjectRef'] ?? '');
		if ($ref === '') {
			return null;
		}

		return 'subject:' . $ref;
	}//end partyOf()

	/**
	 * The parties a session carries as a holder: itself, and its company.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d7-who-holds-a-mandate-decided
	 */
	public function holdersOf(array $subject): array {
		$out = [];
		$ref = (string)($subject['subjectRef'] ?? '');
		if ($ref !== '') {
			$out[] = 'subject:' . $ref;
		}

		$kvk = (string)($subject['kvk'] ?? '');
		if (($subject['provider'] ?? '') === 'eherkenning' && preg_match('/^\d{8}$/', $kvk) === 1) {
			$out[] = 'kvk:' . $kvk;
		}

		return $out;
	}//end holdersOf()

	/**
	 * A party in its typed form: an untyped value of eight digits is a KVK
	 * number, any other untyped value a subject reference.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-mandate-must-name-its-party-in-a-typed-form-req-smr-001
	 */
	public function typed(string $value): string {
		$value = trim($value);
		if ($value === '' || str_starts_with($value, 'kvk:') === true || str_starts_with($value, 'subject:') === true) {
			return $value;
		}

		if (preg_match('/^\d{8}$/', $value) === 1) {
			return 'kvk:' . $value;
		}

		return 'subject:' . $value;
	}//end typed()

	/**
	 * Who holds a mandate: its `holder`, else the account it was written for.
	 *
	 * @param array<string, mixed> $row The mandate.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-company-must-hold-the-mandate-it-accepts-req-smr-005
	 */
	public function holderOf(array $row): string {
		$holder = trim((string)($row['holder'] ?? ''));
		if ($holder !== '') {
			return $this->typed(value: $holder);
		}

		$ref = trim((string)($row['subjectRef'] ?? ''));
		if ($ref === '') {
			return '';
		}

		return 'subject:' . $ref;
	}//end holderOf()
}//end class
