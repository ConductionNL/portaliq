<?php

/**
 * Portaliq Form Step Fetch (data-lookups-and-checks-in-forms, REQ-DIF-005)
 *
 * Sanitises the fetches a form step declares.
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * A step's fetches: each names an integriq service fetch, the answers it
 * sends as inputs, the form fields its outputs fill and, optionally, the
 * check line one output picks. A malformed fetch is dropped on its own; the
 * other fetches of the step stay.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
final class FormStepFetch {
	/**
	 * An id, a fetch slug, an input or an output name.
	 */
	private const TOKEN = '/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/';

	/**
	 * The most fetches one step may declare.
	 */
	private const MAX_FETCHES = 5;

	/**
	 * The declared fetches, sanitised.
	 *
	 * @param mixed              $fetches The declared fetches.
	 * @param array<int, string> $known   The field names the form has.
	 *
	 * @return array<int, array<string, mixed>> The kept fetches; [] when none is valid.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function normalise(mixed $fetches, array $known): array {
		if (is_array($fetches) === false || array_is_list($fetches) === false) {
			return [];
		}

		$kept = [];
		$ids  = [];
		foreach (array_slice($fetches, 0, self::MAX_FETCHES) as $fetch) {
			$clean = $this->fetch(fetch: $fetch, known: $known);
			if ($clean === null || in_array($clean['id'], $ids, true) === true) {
				continue;
			}

			$ids[]  = $clean['id'];
			$kept[] = $clean;
		}

		return $kept;
	}//end normalise()

	/**
	 * One fetch, sanitised, or null when it is malformed.
	 *
	 * @param mixed              $fetch The declared fetch.
	 * @param array<int, string> $known The field names the form has.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch(mixed $fetch, array $known): ?array {
		if (is_array($fetch) === false
			|| $this->isToken(value: ($fetch['id'] ?? null)) === false
			|| $this->isToken(value: ($fetch['source'] ?? null)) === false
		) {
			return null;
		}

		$inputs  = $this->names(declared: ($fetch['inputs'] ?? []), known: $known);
		$outputs = $this->names(declared: ($fetch['outputs'] ?? []), known: $known);
		if ($inputs === null || $outputs === null) {
			return null;
		}

		$clean = ['id' => $fetch['id'], 'source' => $fetch['source'], 'inputs' => $inputs, 'outputs' => $outputs];
		$check = $this->check(check: ($fetch['check'] ?? null));
		if ($check !== null) {
			$clean['check'] = $check;
		}

		if ($outputs === [] && $check === null) {
			// A fetch that fills nothing and shows nothing has no reason to run.
			return null;
		}

		return $clean;
	}//end fetch()

	/**
	 * A map of integriq names to form fields, or null when one is malformed
	 * or names a field the form does not have.
	 *
	 * @param mixed              $declared The declared map.
	 * @param array<int, string> $known    The field names the form has.
	 *
	 * @return array<string, string>|null
	 */
	private function names(mixed $declared, array $known): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$names = [];
		foreach ($declared as $name => $field) {
			if ($this->isToken(value: $name) === false || is_string($field) === false || in_array($field, $known, true) === false) {
				return null;
			}

			$names[$name] = $field;
		}

		return $names;
	}//end names()

	/**
	 * The check line: its label, the output it reads and the line per value.
	 *
	 * @param mixed $check The declared check.
	 *
	 * @return array{label: string, output: string, lines: array<string, string>}|null
	 */
	private function check(mixed $check): ?array {
		if (is_array($check) === false || $this->isToken(value: ($check['output'] ?? null)) === false) {
			return null;
		}

		$text  = new FormText();
		$lines = [];
		foreach ((array)($check['lines'] ?? []) as $value => $line) {
			$clean = $text->clean(value: $line);
			if ($clean !== '') {
				$lines[(string)$value] = $clean;
			}
		}

		if ($lines === []) {
			return null;
		}

		return ['label' => $text->clean(value: ($check['label'] ?? null)), 'output' => $check['output'], 'lines' => $lines];
	}//end check()

	/**
	 * Whether a value is a token.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isToken(mixed $value): bool {
		return is_string($value) === true && preg_match(self::TOKEN, $value) === 1;
	}//end isToken()
}//end class
