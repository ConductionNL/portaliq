<?php

/**
 * Portaliq Document Entry Extras
 *
 * The optional words a documents provider may put on an entry for the site:
 * its own line (`meta`), whether it is new (`isNew`) and a status pill
 * (`status`, `statusTone`). Anything else, or a value of the wrong type, is
 * left out.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/documents-for-each-record/specs/portal-contribution-contract/spec.md#requirement-a-documents-entry-may-carry-its-own-line-a-new-mark-and-a-status
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Keeps the site-facing extras of one documents entry.
 *
 * @spec openspec/changes/documents-for-each-record/specs/portal-contribution-contract/spec.md#requirement-a-documents-entry-may-carry-its-own-line-a-new-mark-and-a-status
 */
class DocumentEntryExtras {

	/**
	 * The text extras an entry may carry.
	 */
	private const TEXT_KEYS = ['meta', 'status', 'statusTone'];

	/**
	 * The longest text extra kept.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * The extras of one entry: short texts and a boolean `isNew`.
	 *
	 * @param array<string, mixed> $entry The entry as the provider returned it.
	 *
	 * @return array<string, string|bool>
	 *
	 * @spec openspec/changes/documents-for-each-record/specs/portal-contribution-contract/spec.md#requirement-a-documents-entry-may-carry-its-own-line-a-new-mark-and-a-status
	 */
	public function extrasOf(array $entry): array {
		$out = [];
		foreach (self::TEXT_KEYS as $key) {
			$text = $this->shortText(value: ($entry[$key] ?? null));
			if ($text !== '') {
				$out[$key] = $text;
			}
		}

		if (is_bool(($entry['isNew'] ?? null)) === true) {
			$out['isNew'] = $entry['isNew'];
		}

		return $out;
	}//end extrasOf()

	/**
	 * A trimmed, non-empty text of at most 200 characters, or ''.
	 *
	 * @param mixed $value The provider's value.
	 *
	 * @return string
	 */
	private function shortText(mixed $value): string {
		if (is_string($value) === false || mb_strlen(trim($value)) > self::MAX_LENGTH) {
			return '';
		}

		return trim($value);
	}//end shortText()
}//end class
