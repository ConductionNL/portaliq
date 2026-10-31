<?php

/**
 * Portaliq Form Operands (form-flow-repeating-groups-calculations-and-decisions)
 *
 * The arguments of a form calculation: numbers, dates and group sub-fields.
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
 * Resolves the arguments of a calculation against the answers so far.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
 */
class PortalFormOperands {
	/**
	 * The number of items in a group, or in any list answer.
	 *
	 * @param mixed $arg The argument, a field name.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return int|null The count, or null when the argument is not a list.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function count(mixed $arg, array $answers): ?int {
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
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function addDays(array $args, array $answers): ?string {
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
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function diffDays(array $args, array $answers): ?int {
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
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function date(mixed $arg, array $answers): ?DateTimeImmutable {
		$values = $this->values(arg: $arg, answers: $answers);
		if (count($values) !== 1 || is_string($values[0]) === false) {
			return null;
		}

		$date = date_create_immutable_from_format('!Y-m-d', $values[0], new DateTimeZone('UTC'));
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
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function values(mixed $arg, array $answers): array {
		if (is_int($arg) === true || is_float($arg) === true) {
			return [$arg];
		}

		if (is_string($arg) === false) {
			return [];
		}

		if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\[\]\.([A-Za-z][A-Za-z0-9_]*)$/', $arg, $match) === 1) {
			return $this->groupValues(group: (array)($answers[$match[1]] ?? []), field: $match[2]);
		}

		if (is_numeric($arg) === true) {
			return [$arg + 0];
		}

		if (array_key_exists($arg, $answers) === false || is_array($answers[$arg]) === true || $answers[$arg] === '') {
			return [];
		}

		return [$answers[$arg]];
	}//end values()

	/**
	 * The values of one sub-field across the rows of a group.
	 *
	 * @param array<int|string, mixed> $group The group's rows.
	 * @param string                   $field The sub-field.
	 *
	 * @return array<int, mixed>
	 */
	private function groupValues(array $group, string $field): array {
		$values = [];
		foreach ($group as $item) {
			if (is_array($item) === true && array_key_exists($field, $item) === true) {
				$values[] = $item[$field];
			}
		}

		return $values;
	}//end groupValues()
}//end class
