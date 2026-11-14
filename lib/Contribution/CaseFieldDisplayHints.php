<?php

/**
 * Portaliq Case Field Display Hints
 *
 * How the portal shows the value of one field on a citizen's own case. A case
 * app may mark a field as personal data (`personal: true`) or name its format
 * (`email`, `telephone`, `uri`); the page then keeps the browser's machine
 * translation off the value. The hints change only how a value is shown,
 * never whether it may be read or written.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/personal-data-left-untranslated/specs/site-chrome/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises declared field display hints and lays them over field states.
 *
 * @spec openspec/changes/personal-data-left-untranslated/specs/site-chrome/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
 */
final class CaseFieldDisplayHints {
	/**
	 * The formats whose values are personal data, as the site reads them.
	 */
	public const FORMATS = ['email', 'uri', 'url', 'telephone', 'tel', 'phone'];

	/**
	 * The declared hints, sanitised: per field name `personal` (only when
	 * true) and `format` (only a known one). A field without either is left
	 * out, so an empty declaration normalises to an empty map.
	 *
	 * The declaration is a map keyed by field name (`{kenteken: {personal:
	 * true}}`) or a list of entries naming their field (`[{field: 'kenteken',
	 * personal: true}]`).
	 *
	 * @param mixed $declared The declaration as authored.
	 *
	 * @return array<string, array{personal?: true, format?: string}>
	 *
	 * @spec openspec/changes/personal-data-left-untranslated/tasks.md#t03
	 */
	public static function normalise(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$hints = [];
		foreach ($declared as $key => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$field = $key;
			if (array_is_list($declared) === true) {
				$field = ($entry['field'] ?? null);
			}

			$hint = self::hintOf(entry: $entry);
			if (is_string($field) === true && $field !== '' && $hint !== []) {
				$hints[$field] = $hint;
			}
		}

		return $hints;
	}//end normalise()

	/**
	 * The field states with each field's hint added. Fields without a hint,
	 * and hints for fields not in the states, change nothing.
	 *
	 * @param array<string, array<string, mixed>> $fields The field states.
	 * @param mixed                               $hints  Sanitised hints.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/personal-data-left-untranslated/tasks.md#t03
	 */
	public static function apply(array $fields, mixed $hints): array {
		if (is_array($hints) === false) {
			return $fields;
		}

		foreach ($fields as $field => $state) {
			$hint = ($hints[$field] ?? null);
			if (is_array($state) === true && is_array($hint) === true) {
				$fields[$field] = array_merge($state, self::hintOf(entry: $hint));
			}
		}

		return $fields;
	}//end apply()

	/**
	 * One entry's hint: `personal` when exactly true, `format` when known.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return array{personal?: true, format?: string}
	 */
	private static function hintOf(array $entry): array {
		$hint = [];
		if (($entry['personal'] ?? null) === true) {
			$hint['personal'] = true;
		}

		$format = ($entry['format'] ?? null);
		if (is_string($format) === true && in_array(strtolower($format), self::FORMATS, true) === true) {
			$hint['format'] = strtolower($format);
		}

		return $hint;
	}//end hintOf()
}//end class
