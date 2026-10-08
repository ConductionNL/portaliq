<?php

/**
 * Portaliq Portal Form Calculator
 *
 * Works out the calculated fields of a published form on the server, so the
 * stored value never comes from the browser.
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
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Six operations: `sum`, `multiply`, `subtract`, `addDays`, `diffDays` and
 * `count`. An argument is a field name, a group sub-field written
 * `group[].field`, or a number. Fields are worked out in the order the form
 * declares them, so a later field can read an earlier calculated one.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
 */
class PortalFormCalculator {

	/**
	 * The operations a form may name.
	 *
	 * @var string[]
	 */
	public const OPERATIONS = ['sum', 'multiply', 'subtract', 'addDays', 'diffDays', 'count'];

	/**
	 * Whether every `calculate` in the fields names an operation this server knows.
	 *
	 * A form that does not pass is refused, as an unknown condition is.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 *
	 * @return bool True when every operation is known and well formed.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function knowsEveryOperation(array $fields): bool {
		return $this->unknownOperations(fields: $fields) === [];
	}//end knowsEveryOperation()

	/**
	 * The operations the fields name that this server does not know, for the
	 * admin's form check.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 *
	 * @return array<int, string> The unknown operation names (a malformed declaration reads as `?`).
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function unknownOperations(array $fields): array {
		$unknown = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			if (array_key_exists('calculate', $field) === true) {
				$calculate = $field['calculate'];
				$op        = '?';
				if (is_array($calculate) === true && is_string($calculate['op'] ?? null) === true) {
					$op = $calculate['op'];
				}

				$args = [];
				if (is_array($calculate) === true && is_array($calculate['args'] ?? null) === true) {
					$args = $calculate['args'];
				}

				if (in_array($op, self::OPERATIONS, true) === false || $args === []) {
					$unknown[] = $op;
				}
			}

			$unknown = array_merge($unknown, $this->unknownOperations(fields: (array)($field['fields'] ?? [])));
		}//end foreach

		return $unknown;
	}//end unknownOperations()

	/**
	 * Replace every calculated field's value with the server's own result.
	 *
	 * A value the browser sent for such a field is dropped first. A result
	 * that cannot be worked out (an argument is missing or not a number) leaves
	 * the field out.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The validated answers.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>} The answers and the names that were calculated.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function apply(array $fields, array $answers): array {
		$computed = [];
		foreach ($fields as $field) {
			if (is_array($field) === false || is_array($field['calculate'] ?? null) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			unset($answers[$name]);
			$result = $this->evaluate(calculate: $field['calculate'], answers: $answers);
			if ($result !== null) {
				$answers[$name] = $result;
				$computed[]     = $name;
			}
		}

		return ['answers' => $answers, 'computed' => $computed];
	}//end apply()

	/**
	 * One calculation over the answers.
	 *
	 * @param array<string, mixed> $calculate The field's `calculate`.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return int|float|string|null The result, or null when it cannot be worked out.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function evaluate(array $calculate, array $answers): int|float|string|null {
		$op   = (string)($calculate['op'] ?? '');
		$args = array_values((array)($calculate['args'] ?? []));
		if (in_array($op, self::OPERATIONS, true) === false || $args === []) {
			return null;
		}

		if ($op === 'count') {
			return $this->count(arg: $args[0], answers: $answers);
		}

		if ($op === 'addDays') {
			return $this->addDays(args: $args, answers: $answers);
		}

		if ($op === 'diffDays') {
			return $this->diffDays(args: $args, answers: $answers);
		}

		$numbers = [];
		foreach ($args as $arg) {
			$values = $this->values(arg: $arg, answers: $answers);
			// A plain argument with no answer cannot be summed over: the result is unknown.
			if ($values === [] && (is_string($arg) === false || str_contains($arg, '[].') === false)) {
				return null;
			}

			foreach ($values as $value) {
				if (is_numeric($value) === false) {
					return null;
				}

				$numbers[] = $value + 0;
			}
		}

		return $this->arithmetic(op: $op, numbers: $numbers);
	}//end evaluate()

	/**
	 * The arithmetic operations over a list of numbers.
	 *
	 * @param string $op `sum`, `multiply` or `subtract`.
	 * @param array<int, int|float> $numbers The numbers.
	 *
	 * @return int|float|null The result, or null for no numbers.
	 */
	private function arithmetic(string $op, array $numbers): int|float|null {
		if ($numbers === []) {
			return null;
		}

		if ($op === 'sum') {
			return array_sum($numbers);
		}

		if ($op === 'multiply') {
			return array_product($numbers);
		}

		$result = array_shift($numbers);
		foreach ($numbers as $number) {
			$result -= $number;
		}

		return $result;
	}//end arithmetic()

	/**
	 * The number of items in a group, or in any list answer.
	 *
	 * @param mixed $arg The argument, a field name.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return int|null The count, or null when the argument is not a list.
	 */
	private function count(mixed $arg, array $answers): ?int {
		$value = $answers[(string)$arg] ?? [];
		if (is_array($value) === true) {
			return count($value);
		}

		return null;
	}//end count()

	/**
	 * A date plus a number of days.
	 *
	 * @param array<int, mixed> $args A date argument and a days argument.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return string|null The date as `Y-m-d`, or null.
	 */
	private function addDays(array $args, array $answers): ?string {
		$date = $this->date(arg: ($args[0] ?? null), answers: $answers);
		$days = $this->values(arg: ($args[1] ?? null), answers: $answers);
		if ($date === null || count($days) !== 1 || is_numeric($days[0]) === false) {
			return null;
		}

		return $date->modify(sprintf('%+d days', (int)$days[0]))->format('Y-m-d');
	}//end addDays()

	/**
	 * The days from the first date to the second.
	 *
	 * @param array<int, mixed> $args Two date arguments.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return int|null The whole days between them, or null.
	 */
	private function diffDays(array $args, array $answers): ?int {
		$from = $this->date(arg: ($args[0] ?? null), answers: $answers);
		$to   = $this->date(arg: ($args[1] ?? null), answers: $answers);
		if ($from === null || $to === null) {
			return null;
		}

		return (int)$from->diff($to)->format('%r%a');
	}//end diffDays()

	/**
	 * One date out of an argument: a real `Y-m-d` answer.
	 *
	 * @param mixed $arg The argument, a field name.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return DateTimeImmutable|null The date at midnight UTC, or null.
	 */
	private function date(mixed $arg, array $answers): ?DateTimeImmutable {
		$values = $this->values(arg: $arg, answers: $answers);
		if (count($values) !== 1 || is_string($values[0]) === false) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $values[0], new DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $values[0]) {
			return null;
		}

		return $date;
	}//end date()

	/**
	 * The values an argument stands for: a number, a field's answer, or every
	 * item's sub-field of a group (`group[].field`).
	 *
	 * @param mixed $arg The argument.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return array<int, mixed> The values; empty when the argument names nothing answered.
	 */
	private function values(mixed $arg, array $answers): array {
		if (is_int($arg) === true || is_float($arg) === true) {
			return [$arg];
		}

		if (is_string($arg) === false) {
			return [];
		}

		if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\[\]\.([A-Za-z][A-Za-z0-9_]*)$/', $arg, $match) === 1) {
			$values = [];
			foreach ((array)($answers[$match[1]] ?? []) as $item) {
				if (is_array($item) === true && array_key_exists($match[2], $item) === true) {
					$values[] = $item[$match[2]];
				}
			}

			return $values;
		}

		if (is_numeric($arg) === true) {
			return [$arg + 0];
		}

		if (array_key_exists($arg, $answers) === false || is_array($answers[$arg]) === true || $answers[$arg] === '') {
			return [];
		}

		return [$answers[$arg]];
	}//end values()
}//end class
