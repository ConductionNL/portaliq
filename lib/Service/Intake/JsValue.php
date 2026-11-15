<?php

/**
 * Portaliq JavaScript Value
 *
 * JavaScript's String(), Number(), truthiness and property access for the values a decoded JSON answer
 * can hold, so the server's visibleWhen comparison coerces exactly as
 * nextcloud-vue's does in the browser: `3` and `"3"` are equal, `""` is
 * zero, a missing answer is `undefined` and so NaN.
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
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * JavaScript's String() and Number().
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
class JsValue {

	/**
	 * JavaScript's `String(value)`.
	 *
	 * @param bool $found False for `undefined`.
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function text(bool $found, mixed $value): string {
		if ($found === false) {
			return 'undefined';
		}

		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_float($value) === true) {
			return $this->floatText(value: $value);
		}

		if (is_array($value) === true) {
			return $this->arrayText(value: $value);
		}

		return (string)$value;
	}//end text()

	/**
	 * JavaScript's `Number(value)`; NAN where it gives NaN.
	 *
	 * @param bool $found False for `undefined`.
	 * @param mixed $value The value.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function number(bool $found, mixed $value): float {
		if ($found === false) {
			return NAN;
		}

		if ($value === null || $value === false) {
			return 0.0;
		}

		if ($value === true) {
			return 1.0;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_array($value) === true) {
			return $this->arrayNumber(value: $value);
		}

		return $this->textNumber(text: (string)$value);
	}//end number()

	/**
	 * Read a dotted path off the answers.
	 *
	 * @param array<string, mixed> $answers The answers.
	 * @param string $field The dotted path.
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 *
	 * @return array{0: bool, 1: mixed} Whether the path exists (JavaScript's
	 *         `undefined` is "not found"), and the value there.
	 */
	public function readPath(array $answers, string $field): array {
		$found = true;
		$value = $answers;
		foreach (explode('.', $field) as $key) {
			if ($found === false || $value === null) {
				// Past a missing or a null step, JavaScript keeps what it has.
				continue;
			}

			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				$found = false;
				$value = null;
				continue;
			}

			$value = $value[$key];
		}

		return [$found, $value];
	}//end readPath()

	/**
	 * JavaScript truthiness, for the `endpoint` / `source` mode switch.
	 *
	 * @param mixed $value The value.
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 *
	 * @return bool
	 */
	public function isTruthy(mixed $value): bool {
		if (is_array($value) === true) {
			return true;
		}

		return ($value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0);
	}//end isTruthy()

	/**
	 * An array as text: `[object Object]` for an object, else its items
	 * joined with commas, null blank.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return string
	 */
	private function arrayText(array $value): string {
		if (array_is_list($value) === false) {
			return '[object Object]';
		}

		$items = [];
		foreach ($value as $item) {
			$items[] = '';
			if ($item !== null) {
				$items[array_key_last($items)] = $this->text(found: true, value: $item);
			}
		}

		return implode(',', $items);
	}//end arrayText()

	/**
	 * An array as a number: an empty one is zero, one item is that item's
	 * text read as a number, anything else NaN.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return float
	 */
	private function arrayNumber(array $value): float {
		if ($value === []) {
			return 0.0;
		}

		if (array_is_list($value) === true && count($value) === 1) {
			return $this->textNumber(text: $this->text(found: true, value: $value[0]));
		}

		return NAN;
	}//end arrayNumber()

	/**
	 * A float the way JavaScript prints it: `3`, not `3.0`.
	 *
	 * @param float $value The number.
	 *
	 * @return string
	 */
	private function floatText(float $value): string {
		if (is_nan($value) === true) {
			return 'NaN';
		}

		if (is_infinite($value) === true) {
			return str_replace('INF', 'Infinity', (string)$value);
		}

		if (floor($value) === $value && abs($value) < 1e21) {
			return sprintf('%.0f', $value);
		}

		return (string)json_encode($value);
	}//end floatText()

	/**
	 * `Number(text)`: trimmed, blank is zero, otherwise a decimal or a
	 * hexadecimal literal, else NaN.
	 *
	 * @param string $text The text.
	 *
	 * @return float
	 */
	private function textNumber(string $text): float {
		$text = trim($text);
		if ($text === '') {
			return 0.0;
		}

		if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text) === 1) {
			return (float)$text;
		}

		if (preg_match('/^0[xX][0-9a-fA-F]+$/', $text) === 1) {
			return (float)hexdec(substr($text, 2));
		}

		return match ($text) {
			'Infinity', '+Infinity' => INF,
			'-Infinity' => -INF,
			default => NAN,
		};
	}//end textNumber()
}//end class
