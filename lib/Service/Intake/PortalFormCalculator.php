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
	 * Constructor.
	 *
	 * @param PortalFormOperands $operands Reads the arguments of a calculation.
	 */
	public function __construct(
		private readonly PortalFormOperands $operands = new PortalFormOperands(),
	) {
	}//end __construct()


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

			if (array_key_exists('calculate', $field) === true && $this->isUnknown(calculate: $field['calculate']) === true) {
				$unknown[] = $this->operationOf(calculate: $field['calculate']);
			}

			$unknown = array_merge($unknown, $this->unknownOperations(fields: (array)($field['fields'] ?? [])));
		}//end foreach

		return $unknown;
	}//end unknownOperations()

	/**
	 * The operation a calculation names, or '?' when it names none.
	 *
	 * @param mixed $calculate The field's `calculate`.
	 *
	 * @return string
	 */
	private function operationOf(mixed $calculate): string {
		if (is_array($calculate) === true && is_string($calculate['op'] ?? null) === true) {
			return $calculate['op'];
		}

		return '?';
	}//end operationOf()

	/**
	 * Whether the server cannot repeat a calculation: an unknown operation or no arguments.
	 *
	 * @param mixed $calculate The field's `calculate`.
	 *
	 * @return bool
	 */
	private function isUnknown(mixed $calculate): bool {
		$args = [];
		if (is_array($calculate) === true && is_array($calculate['args'] ?? null) === true) {
			$args = $calculate['args'];
		}

		return in_array($this->operationOf(calculate: $calculate), self::OPERATIONS, true) === false || $args === [];
	}//end isUnknown()

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
			return $this->operands->count(arg: $args[0], answers: $answers);
		}

		if ($op === 'addDays') {
			return $this->operands->addDays(args: $args, answers: $answers);
		}

		if ($op === 'diffDays') {
			return $this->operands->diffDays(args: $args, answers: $answers);
		}

		$numbers = $this->numbersOf(args: $args, answers: $answers);
		if ($numbers === null) {
			return null;
		}

		return $this->arithmetic(op: $op, numbers: $numbers);
	}//end evaluate()

	/**
	 * The numbers every argument stands for, or null when one of them has no answer or is no number.
	 *
	 * @param array<int, mixed>    $args    The calculation's arguments.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return array<int, int|float>|null
	 */
	private function numbersOf(array $args, array $answers): ?array {
		$numbers = [];
		foreach ($args as $arg) {
			$values = $this->operands->values(arg: $arg, answers: $answers);
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

		return $numbers;
	}//end numbersOf()

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

}//end class
