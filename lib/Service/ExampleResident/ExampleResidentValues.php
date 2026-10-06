<?php

/**
 * Portaliq Example Resident Values
 *
 * Fills in the values a declaration leaves open until the install runs: the
 * resident's reference, an id found by a lookup, a field of an object written
 * earlier, and dates counted from the day of the install. Pure: it reads
 * nothing and writes nothing, so its rules are tested without an instance.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use DateTimeImmutable;

/**
 * Replaces `{{...}}` in declared values.
 *
 * The forms: `{{subject}}`, `{{lookup:name}}`, `{{object:key.field}}`,
 * `{{date:n}}` and `{{datetime:n HH:MM}}`, where n is a number of days from
 * the day of the install (negative is the past).
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */
class ExampleResidentValues {
	/**
	 * Fill a declared value, at any depth.
	 *
	 * A string that is one placeholder becomes the value it stands for; a
	 * string with a placeholder inside it stays a string. A placeholder that
	 * cannot be filled is added to `$gaps` and left as it is, so the caller
	 * can refuse to write the object and name why.
	 *
	 * @param mixed                $value   The declared value.
	 * @param array<string, mixed> $context `subject`, `lookups` (id by name), `objects` (row by key) and `today`.
	 * @param array<int, string>   $gaps    Collects every placeholder that could not be filled.
	 *
	 * @return mixed The filled value.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function fill(mixed $value, array $context, array &$gaps): mixed {
		if (is_array($value) === true) {
			$filled = [];
			foreach ($value as $key => $item) {
				$filled[$key] = $this->fill(value: $item, context: $context, gaps: $gaps);
			}

			return $filled;
		}

		if (is_string($value) === false || str_contains($value, '{{') === false) {
			return $value;
		}

		return preg_replace_callback(
			'/\{\{([^{}]+)\}\}/',
			function (array $match) use ($context, &$gaps): string {
				$found = $this->valueOf(placeholder: trim($match[1]), context: $context);
				if ($found === null) {
					$gaps[] = $match[0];
					return $match[0];
				}

				return $found;
			},
			$value
		);
	}//end fill()

	/**
	 * What one placeholder stands for, or null when it cannot be filled.
	 *
	 * @param string               $placeholder The text between the braces.
	 * @param array<string, mixed> $context     See fill().
	 *
	 * @return string|null
	 */
	private function valueOf(string $placeholder, array $context): ?string {
		[$kind, $rest] = array_pad(explode(':', $placeholder, 2), 2, '');
		$rest          = trim($rest);
		if ($kind === 'subject') {
			return $this->text(value: ($context['subject'] ?? null));
		}

		if ($kind === 'lookup') {
			return $this->text(value: ($context['lookups'][$rest] ?? null));
		}

		if ($kind === 'object') {
			[$key, $field] = array_pad(explode('.', $rest, 2), 2, '');

			return $this->text(value: ($context['objects'][$key][$field] ?? null));
		}

		if ($kind === 'date' || $kind === 'datetime') {
			return $this->moment(kind: $kind, rest: $rest, today: ($context['today'] ?? null));
		}

		return null;
	}//end valueOf()

	/**
	 * A day, or a moment on a day, counted from the day of the install.
	 *
	 * @param string $kind  `date` or `datetime`.
	 * @param string $rest  The number of days, and for a moment the time as HH:MM.
	 * @param mixed  $today The day of the install.
	 *
	 * @return string|null `2026-10-05`, or `2026-10-05T09:12:00+02:00`; null when the placeholder is malformed.
	 */
	private function moment(string $kind, string $rest, mixed $today): ?string {
		$pattern = '/^(-?\d{1,4})$/';
		if ($kind === 'datetime') {
			$pattern = '/^(-?\d{1,4}) ([01]\d|2[0-3]):([0-5]\d)$/';
		}

		if (($today instanceof DateTimeImmutable) === false || preg_match($pattern, $rest, $parts) !== 1) {
			return null;
		}

		$day = $today->modify(sprintf('%+d days', (int)$parts[1]));
		if ($kind === 'date') {
			return $day->format('Y-m-d');
		}

		return $day->setTime((int)$parts[2], (int)$parts[3])->format('c');
	}//end moment()

	/**
	 * A found value as text, or null when there is none.
	 *
	 * @param mixed $value The found value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return null;
	}//end text()
}//end class
