<?php

/**
 * Portaliq Write Refusal (site-action-forms)
 *
 * When OpenRegister refuses a portal write because a value does not fit the
 * schema ("Property 'hoursSubmitted' should be type 'number' but is
 * 'string'"), the site used to hear only 502 `write_failed` and said "this is
 * not available right now". This reads which field was refused and what kind
 * of value it wants from the store's message, and answers 422 with
 * `invalid: {field: kind}`, so the form can say in plain words which field to
 * change.
 *
 * Only a field the portal itself wrote is named, and only a kind from a short
 * list: the store's own text never leaves the server.
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
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Turns a store refusal of a written value into a 422 that names the field.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
 */
class WriteRefusal {
	/**
	 * The kinds a refused value may have, by the schema type the store named.
	 */
	private const TYPE_KINDS = [
		'number'  => 'number',
		'integer' => 'integer',
		'boolean' => 'boolean',
	];

	/**
	 * The kinds by the format the store named.
	 */
	private const FORMAT_KINDS = [
		'date'      => 'date',
		'date-time' => 'date',
	];

	/**
	 * The 422 that names the refused fields, or null when the refusal names
	 * none of the written fields.
	 *
	 * @param string               $failure The store's refusal (PortalObjectWriter::lastFailure()).
	 * @param array<string, mixed> $data    The body the portal wrote.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
	 */
	public function response(string $failure, array $data): ?JSONResponse {
		$invalid = $this->invalidFields(failure: $failure, fields: array_map('strval', array_keys($data)));
		if ($invalid === []) {
			return null;
		}

		return new JSONResponse(['error' => 'invalid', 'invalid' => $invalid], Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end response()

	/**
	 * Which of the fields the refusal names, each with the kind of value it
	 * wants: number, integer, boolean, date, required, or invalid.
	 *
	 * @param string             $failure The store's refusal.
	 * @param array<int, string> $fields  The fields the portal wrote.
	 *
	 * @return array<string, string> The kind per refused field.
	 *
	 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
	 */
	public function invalidFields(string $failure, array $fields): array {
		$out = [];
		$found = preg_match_all(
			"/Property '([^']+)'([^.]*)|required property \\(([^)]+)\\)/",
			$failure,
			$matches,
			PREG_SET_ORDER
		);
		if ($found === false || $found === 0) {
			return [];
		}

		foreach ($matches as $match) {
			$required = (($match[3] ?? '') !== '');
			$field = $this->fieldOf(path: ($match[3] ?? '') !== '' ? $match[3] : $match[1]);
			if (in_array($field, $fields, true) === false || isset($out[$field]) === true) {
				continue;
			}

			$out[$field] = $required === true ? 'required' : $this->kindOf(rest: ($match[2] ?? ''));
		}

		return $out;
	}//end invalidFields()

	/**
	 * The top-level field of a property path (`/hoursSubmitted`,
	 * `address.street`).
	 *
	 * @param string $path The path.
	 *
	 * @return string The field.
	 */
	private function fieldOf(string $path): string {
		$parts = preg_split('#[/.]#', trim($path), -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false || $parts === []) {
			return '';
		}

		return (string)$parts[0];
	}//end fieldOf()

	/**
	 * The kind of value the rest of the store's sentence asks for.
	 *
	 * @param string $rest What follows the property name.
	 *
	 * @return string The kind.
	 */
	private function kindOf(string $rest): string {
		if (preg_match("/should be type '([a-z]+)'/", $rest, $type) === 1 && isset(self::TYPE_KINDS[$type[1]]) === true) {
			return self::TYPE_KINDS[$type[1]];
		}

		if (preg_match("/should match format '([a-z-]+)'/", $rest, $format) === 1 && isset(self::FORMAT_KINDS[$format[1]]) === true) {
			return self::FORMAT_KINDS[$format[1]];
		}

		return 'invalid';
	}//end kindOf()
}//end class
