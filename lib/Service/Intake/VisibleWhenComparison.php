<?php

/**
 * Portaliq VisibleWhen Comparison
 *
 * The operators of a local visibleWhen condition, as nextcloud-vue's
 * compareVisibleWhen applies them: `empty` and `notEmpty` on blankness,
 * `eq` and `neq` on JavaScript's String(), the ordering operators on its
 * Number(). VisibleWhenLocal resolves the two sides; this class compares them.
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
 * Compares the two sides of a visibleWhen condition.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
class VisibleWhenComparison {

	/**
	 * Constructor.
	 *
	 * @param JsValue $javascript JavaScript's String() and Number().
	 */
	public function __construct(
		private readonly JsValue $javascript = new JsValue(),
	) {
	}//end __construct()

	/**
	 * Whether the comparison holds.
	 *
	 * @param bool $found Whether the left-hand answer exists (JavaScript's `undefined` is not found).
	 * @param mixed $actual The left-hand answer.
	 * @param string $operator One of VisibleWhenLocal::OPS.
	 * @param mixed $expected The resolved right-hand value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function holds(bool $found, mixed $actual, string $operator, mixed $expected): bool {
		if ($operator === 'empty') {
			return $this->isBlank(found: $found, actual: $actual);
		}

		if ($operator === 'notEmpty') {
			return ($this->isBlank(found: $found, actual: $actual) === false);
		}

		if ($operator === 'eq') {
			return $this->isEqual(found: $found, actual: $actual, expected: $expected);
		}

		if ($operator === 'neq') {
			return ($this->isEqual(found: $found, actual: $actual, expected: $expected) === false);
		}

		return $this->isOrdered(found: $found, actual: $actual, operator: $operator, expected: $expected);
	}//end holds()

	/**
	 * Unset, null, an empty text or an empty list.
	 *
	 * @param bool $found Whether the answer exists.
	 * @param mixed $actual The answer.
	 *
	 * @return bool
	 */
	private function isBlank(bool $found, mixed $actual): bool {
		return ($found === false || $actual === null || $actual === '' || $actual === []);
	}//end isBlank()

	/**
	 * `actual === expected || String(actual) === String(expected)`.
	 *
	 * @param bool $found Whether the answer exists.
	 * @param mixed $actual The answer.
	 * @param mixed $expected The value compared with.
	 *
	 * @return bool
	 */
	private function isEqual(bool $found, mixed $actual, mixed $expected): bool {
		if ($found === true && is_scalar($actual) === true && $actual === $expected) {
			return true;
		}

		return ($this->javascript->text(found: $found, value: $actual) === $this->javascript->text(found: true, value: $expected));
	}//end isEqual()

	/**
	 * `gt`, `gte`, `lt` or `lte` over Number() of both sides; false when
	 * either is no finite number.
	 *
	 * @param bool $found Whether the answer exists.
	 * @param mixed $actual The answer.
	 * @param string $operator The ordering operator.
	 * @param mixed $expected The value compared with.
	 *
	 * @return bool
	 */
	private function isOrdered(bool $found, mixed $actual, string $operator, mixed $expected): bool {
		$left = $this->javascript->number(found: $found, value: $actual);
		$right = $this->javascript->number(found: true, value: $expected);
		if (is_finite($left) === false || is_finite($right) === false) {
			return false;
		}

		return match ($operator) {
			'gt' => ($left > $right),
			'gte' => ($left >= $right),
			'lt' => ($left < $right),
			default => ($left <= $right),
		};
	}//end isOrdered()
}//end class
