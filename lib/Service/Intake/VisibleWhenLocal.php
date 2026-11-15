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
	 * Constructor.
	 *
	 * @param VisibleWhenComparison $comparison The operators, with JavaScript's coercion.
	 * @param JsValue $javascript JavaScript's property access and truthiness.
	 */
	public function __construct(
		private readonly VisibleWhenComparison $comparison = new VisibleWhenComparison(),
		private readonly JsValue $javascript = new JsValue(),
	) {
	}//end __construct()

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
		if ($parts === null) {
			return $this->leafIsVisible(condition: $condition, answers: $answers);
		}

		$results = array_map(fn (mixed $part): bool => $this->isVisible(condition: $part, answers: $answers), $parts);
		if ($this->isList(value: ($condition['all'] ?? null)) === true) {
			return (in_array(false, $results, true) === false);
		}

		return in_array(true, $results, true);
	}//end isVisible()

	/**
	 * Whether the portal can check every field's condition of a form.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 */
	public function decidesEveryField(array $fields): bool {
		foreach ($fields as $field) {
			if (is_array($field) === true && $this->isDecidable(condition: ($field['visibleWhen'] ?? null)) === false) {
				return false;
			}
		}

		return true;
	}//end decidesEveryField()

	/**
	 * One condition that is no `all` / `any` composition.
	 *
	 * @param array<string, mixed> $condition The condition.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return bool
	 */
	private function leafIsVisible(array $condition, array $answers): bool {
		if ($this->asksElsewhere(condition: $condition) === true) {
			// An endpoint, a source or an installed app: no app answers a
			// public form, and the server replays no request.
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

		[$found, $actual] = $this->javascript->readPath(answers: $answers, field: $field);

		return $this->comparison->holds(
			found: $found,
			actual: $actual,
			operator: $operator,
			expected: $this->resolveToken(value: ($condition['value'] ?? null), answers: $answers)
		);
	}//end leafIsVisible()

	/**
	 * Whether a condition asks a server (`endpoint`, `source`) or the
	 * resident's installed apps (`appInstalled`) rather than the answers.
	 *
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return bool
	 */
	private function asksElsewhere(array $condition): bool {
		if ($this->javascript->isTruthy(value: ($condition['endpoint'] ?? null)) === true
			|| $this->javascript->isTruthy(value: ($condition['source'] ?? null)) === true
		) {
			return true;
		}

		$app = ($condition['appInstalled'] ?? null);
		return (is_string($app) === true && $app !== '');
	}//end asksElsewhere()

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

		if ($this->asksElsewhere(condition: $condition) === true) {
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
