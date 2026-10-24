<?php

/**
 * Portaliq Form Step Decision (form-flow-repeating-groups-calculations-and-decisions)
 *
 * Sanitises the decision a form step declares.
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
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * A step's decision: a rule, the inputs it reads, the field its outcome
 * fills and the step each outcome opens. Anything malformed drops the whole
 * decision, so a half-declared rule never runs.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */
class FormStepDecision {
	/**
	 * The token a rule, an input name, a path or a step id must match.
	 */
	private const TOKEN = '/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/';

	/**
	 * Sanitise a declared decision.
	 *
	 * @param mixed $decision The declared decision.
	 * @param array<int, string> $known The known field names.
	 *
	 * @return array<string, mixed>|null The decision, or null to drop it.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function normalise(mixed $decision, array $known): ?array {
		if (is_array($decision) === false) {
			return null;
		}

		$rule   = ($decision['rule'] ?? null);
		$output = ($decision['output'] ?? null);
		if ($this->isToken(value: $rule) === false) {
			return null;
		}

		if (is_string($output) === false || in_array($output, $known, true) === false) {
			return null;
		}

		$inputs = $this->inputs(declared: ($decision['inputs'] ?? []));
		if ($inputs === null) {
			return null;
		}

		return ['rule' => $rule, 'inputs' => $inputs, 'output' => $output, 'nextStep' => $this->nextSteps(declared: ($decision['nextStep'] ?? []))];
	}//end normalise()

	/**
	 * Whether a value is a plain token.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it is a string matching the token shape.
	 */
	private function isToken(mixed $value): bool {
		return is_string($value) === true && preg_match(self::TOKEN, $value) === 1;
	}//end isToken()

	/**
	 * The inputs of a decision, or null when one of them is malformed.
	 *
	 * @param mixed $declared The declared inputs.
	 *
	 * @return array<string, string>|null The inputs, or null to drop the decision.
	 */
	private function inputs(mixed $declared): ?array {
		$inputs = [];
		foreach ((array)$declared as $name => $path) {
			if ($this->isToken(value: $name) === false || $this->isToken(value: $path) === false) {
				return null;
			}

			$inputs[$name] = $path;
		}

		return $inputs;
	}//end inputs()

	/**
	 * The step each outcome opens; a malformed target is skipped.
	 *
	 * @param mixed $declared The declared `nextStep` map.
	 *
	 * @return array<string, string> The targets.
	 */
	private function nextSteps(mixed $declared): array {
		$next = [];
		foreach ((array)$declared as $outcome => $stepId) {
			if ($this->isToken(value: $stepId) === true) {
				$next[(string)$outcome] = $stepId;
			}
		}

		return $next;
	}//end nextSteps()
}//end class
