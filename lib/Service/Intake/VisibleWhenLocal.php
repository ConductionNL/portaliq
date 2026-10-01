<?php

/**
 * Portaliq VisibleWhen Local Evaluator
 *
 * The server's reading of a form field's `visibleWhen` condition in local mode:
 * `{field, op, value}` over the answers so far, composed with `all` / `any`.
 * The screen evaluates the same condition with nextcloud-vue's
 * `evaluateVisibleWhenLocal`, and the server cannot trust the screen to have
 * hidden a field, so it asks the question again on submit.
 *
 * This is a second evaluator of one grammar, which is how grammars drift. It is
 * pinned to nextcloud-vue's by tests/fixtures/visible-when-local.json, which
 * tests/visible-when-local.spec.mjs runs against the JS predicate and
 * tests/Unit/Service/Intake/VisibleWhenLocalTest.php runs against this class.
 * When openregister ships a server-side evaluator with its journey run API,
 * portaliq calls that and deletes this class.
 *
 * It reproduces JavaScript's coercion where the predicate relies on it: `eq`
 * compares `String(a) === String(b)`, ordering compares `Number(a)` with
 * `Number(b)`, and a missing answer is `undefined`, not null.
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
 * Local-mode visibleWhen, evaluated on the server.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
class VisibleWhenLocal {

	/**
	 * The operators nextcloud-vue's VISIBLE_WHEN_OPS names; any other is `eq`.
	 *
	 * @var array<int, string>
	 */
	public const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'notEmpty'];

	/**
	 * Right-hand tokens that read the clock. The browser resolves them in the
	 * resident's own time zone and day, so the server cannot replay them.
	 *
	 * @var array<int, string>
	 */
	private const CLOCK_TOKENS = ['@now', '@today', '@monthStart', '@quarterStart', '@yearStart', '@currentFiscalYear'];

	/**
	 * Whether the field this condition guards is shown, given the answers.
	 *
	 * A null condition shows the field. Anything else that is not a condition
	 * hides it, as the screen does: a broken condition hides one question, it
	 * never shows a question its author meant to hide.
	 *
	 * @param mixed $condition The field's `visibleWhen`.
	 * @param array<string, mixed> $answers The answers so far, the object a dotted `field` reads.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function isVisible(mixed $condition, array $answers): bool {
		if ($condition === null) {
			return true;
		}

		if ($this->isObject(value: $condition) === false) {
			return false;
		}

		$parts = $this->partsOf(condition: $condition);
		if ($parts !== null) {
			$results = array_map(fn (mixed $part): bool => $this->isVisible(condition: $part, answers: $answers), $parts);
			if ($this->isList(value: ($condition['all'] ?? null)) === true) {
				return (in_array(false, $results, true) === false);
			}

			return in_array(true, $results, true);
		}

		if ($this->isTruthy(value: ($condition['endpoint'] ?? null)) === true
			|| $this->isTruthy(value: ($condition['source'] ?? null)) === true
		) {
			return false;
		}

		$app = ($condition['appInstalled'] ?? null);
		if (is_string($app) === true && $app !== '') {
			// No app answers a public form: the resident has no Nextcloud
			// session for one to be installed for.
			return false;
		}

		$field = ($condition['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$operator = ($condition['op'] ?? null);
		if (in_array($operator, self::OPS, true) === false) {
			$operator = 'eq';
		}

		[$found, $actual] = $this->readPath(answers: $answers, field: $field);

		return $this->compare(
			found: $found,
			actual: $actual,
			operator: $operator,
			expected: $this->resolveToken(value: ($condition['value'] ?? null), answers: $answers)
		);
	}//end isVisible()

	/**
	 * Whether the server can decide this condition the way the screen does.
	 *
	 * Not when it asks a server (`endpoint`, `source`), the clock (`@today`
	 * and its kin) or the resident's installed apps (`appInstalled`): a form
	 * carrying such a condition is refused rather than half checked
	 * (REQ-ICQ-003).
	 *
	 * @param mixed $condition The field's `visibleWhen`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 */
	public function isDecidable(mixed $condition): bool {
		if ($this->isObject(value: $condition) === false) {
			// Null shows, anything else hides, on both sides alike.
			return true;
		}

		$parts = $this->partsOf(condition: $condition);
		if ($parts !== null) {
			foreach ($parts as $part) {
				if ($this->isDecidable(condition: $part) === false) {
					return false;
				}
			}

			return true;
		}

		if ($this->isTruthy(value: ($condition['endpoint'] ?? null)) === true
			|| $this->isTruthy(value: ($condition['source'] ?? null)) === true
		) {
			return false;
		}

		$app = ($condition['appInstalled'] ?? null);
		if (is_string($app) === true && $app !== '') {
			return false;
		}

		return ($this->readsTheClock(value: ($condition['value'] ?? null)) === false);
	}//end isDecidable()

	/**
	 * The parts of an `all` / `any` composition, or null when it is none.
	 *
	 * `all` wins when it is a list; an `all` that is no list falls through to
	 * `any`, as nextcloud-vue reads it.
	 *
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return array<int, mixed>|null
	 */
	private function partsOf(array $condition): ?array {
		$all = ($condition['all'] ?? null);
		if ($this->isList(value: $all) === true) {
			return $all;
		}

		$any = ($condition['any'] ?? null);
		if ($this->isList(value: $any) === true) {
			return $any;
		}

		return null;
	}//end partsOf()

	/**
	 * Whether a value right of the operator is a token that reads the clock.
	 *
	 * @param mixed $value The condition's `value`.
	 *
	 * @return bool
	 */
	private function readsTheClock(mixed $value): bool {
		if (is_string($value) === false) {
			return false;
		}

		return (in_array($value, self::CLOCK_TOKENS, true) === true
			|| preg_match('/^@today([+-]\d+)d$/', $value) === 1);
	}//end readsTheClock()

	/**
	 * Read a dotted path off the answers.
	 *
	 * @param array<string, mixed> $answers The answers.
	 * @param string $field The dotted path.
	 *
	 * @return array{0: bool, 1: mixed} Whether the path exists (JavaScript's
	 *         `undefined` is "not found"), and the value there.
	 */
	private function readPath(array $answers, string $field): array {
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
	 * Resolve the right-hand value the way nextcloud-vue's resolveFilterValue
	 * does for a form: `@object.<answer>` reads another answer, `@me` is
	 * nobody on a public form, and any other text is itself.
	 *
	 * @param mixed $value The condition's `value`.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return mixed
	 */
	private function resolveToken(mixed $value, array $answers): mixed {
		if (is_string($value) === false || str_starts_with($value, '@') === false) {
			return $value;
		}

		if ($value === '@me') {
			return '';
		}

		if (str_starts_with($value, '@object.') === true) {
			$field = substr($value, strlen('@object.'));
			if ($field !== '' && array_key_exists($field, $answers) === true) {
				return $answers[$field];
			}
		}

		return $value;
	}//end resolveToken()

	/**
	 * Apply the operator, as nextcloud-vue's compareVisibleWhen does.
	 *
	 * @param bool $found Whether the left-hand answer exists.
	 * @param mixed $actual The left-hand answer.
	 * @param string $operator One of OPS.
	 * @param mixed $expected The resolved right-hand value.
	 *
	 * @return bool
	 */
	private function compare(bool $found, mixed $actual, string $operator, mixed $expected): bool {
		if ($operator === 'empty' || $operator === 'notEmpty') {
			$blank = ($found === false || $actual === null || $actual === '' || $actual === []);
			return ($operator === 'empty') ? $blank : ($blank === false);
		}

		if ($operator === 'eq' || $operator === 'neq') {
			$equal = (($found === true && is_scalar($actual) === true && $actual === $expected)
				|| $this->toText(found: $found, value: $actual) === $this->toText(found: true, value: $expected));
			return ($operator === 'eq') ? $equal : ($equal === false);
		}

		$left = $this->toNumber(found: $found, value: $actual);
		$right = $this->toNumber(found: true, value: $expected);
		if (is_finite($left) === false || is_finite($right) === false) {
			return false;
		}

		return match ($operator) {
			'gt' => ($left > $right),
			'gte' => ($left >= $right),
			'lt' => ($left < $right),
			default => ($left <= $right),
		};
	}//end compare()

	/**
	 * JavaScript's `String(value)`.
	 *
	 * @param bool $found False for `undefined`.
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function toText(bool $found, mixed $value): string {
		if ($found === false) {
			return 'undefined';
		}

		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return ($value === true) ? 'true' : 'false';
		}

		if (is_float($value) === true) {
			return $this->floatText(value: $value);
		}

		if (is_array($value) === false) {
			return (string)$value;
		}

		if (array_is_list($value) === false) {
			return '[object Object]';
		}

		// An array joins its items with commas, and null in it is blank.
		return implode(
			',',
			array_map(fn (mixed $item): string => ($item === null) ? '' : $this->toText(found: true, value: $item), $value)
		);
	}//end toText()

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
			return ($value > 0) ? 'Infinity' : '-Infinity';
		}

		if (floor($value) === $value && abs($value) < 1e21) {
			return sprintf('%.0f', $value);
		}

		return (string)json_encode($value);
	}//end floatText()

	/**
	 * JavaScript's `Number(value)`; NAN where it gives NaN.
	 *
	 * @param bool $found False for `undefined`.
	 * @param mixed $value The value.
	 *
	 * @return float
	 */
	private function toNumber(bool $found, mixed $value): float {
		if ($found === false) {
			return NAN;
		}

		if ($value === null) {
			return 0.0;
		}

		if (is_bool($value) === true) {
			return ($value === true) ? 1.0 : 0.0;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_array($value) === true) {
			if ($value === []) {
				return 0.0;
			}

			if (array_is_list($value) === true && count($value) === 1) {
				return $this->textToNumber(text: $this->toText(found: true, value: $value[0]));
			}

			return NAN;
		}

		return $this->textToNumber(text: (string)$value);
	}//end toNumber()

	/**
	 * JavaScript's `Number(text)`: trimmed, blank is zero, otherwise a decimal
	 * or a hexadecimal literal, else NaN.
	 *
	 * @param string $text The text.
	 *
	 * @return float
	 */
	private function textToNumber(string $text): float {
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
	}//end textToNumber()

	/**
	 * JavaScript truthiness, for the `endpoint` / `source` mode switch.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isTruthy(mixed $value): bool {
		if (is_array($value) === true) {
			return true;
		}

		return ($value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0);
	}//end isTruthy()

	/**
	 * Whether a decoded JSON value was an object: an array with keys.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isObject(mixed $value): bool {
		return (is_array($value) === true && $value !== [] && array_is_list($value) === false);
	}//end isObject()

	/**
	 * Whether a decoded JSON value was a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isList(mixed $value): bool {
		return (is_array($value) === true && array_is_list($value) === true);
	}//end isList()
}//end class
