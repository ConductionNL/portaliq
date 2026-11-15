<?php

/**
 * Portaliq Submission Decisions (form-flow-repeating-groups-calculations-and-decisions)
 *
 * The calculated fields and the decided ones of a form, worked out again on
 * the server whatever the browser sent.
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

use OCA\Portaliq\Kernel\Contracts\PortalFormCalculatorInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormDecisionInterface;

/**
 * Works out a submission's calculated fields and asks the rule engine for
 * the decisions its steps declare. Built by PortalSubmissionChecks.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */
final class SubmissionDecisions {
	/**
	 * Constructor.
	 *
	 * @param PortalFormCalculatorInterface|null $calculator Works out the form's calculated fields.
	 * @param PortalFormDecisionInterface|null   $decision   Asks the rule engine for the decisions a form's steps declare.
	 */
	public function __construct(
		private readonly ?PortalFormCalculatorInterface $calculator,
		private readonly ?PortalFormDecisionInterface $decision,
	) {
	}//end __construct()

	/**
	 * The decision the step with this id declares; the last such step wins.
	 *
	 * @param array<int, mixed> $steps The form's steps.
	 * @param string            $step  The id of the step.
	 *
	 * @return array<string, mixed>|null The declared decision, or null when none is.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function declaredDecision(array $steps, string $step): ?array {
		$declared = null;
		foreach ($steps as $candidate) {
			if (($candidate['id'] ?? null) === $step && is_array($candidate['decision'] ?? null) === true) {
				$declared = $candidate['decision'];
			}
		}

		return $declared;
	}//end declaredDecision()

	/**
	 * The answers to the form's own fields, with the calculated fields worked out.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $answers The answers so far.
	 *
	 * @return array<string, mixed> The known answers.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function knownAnswers(array $fields, array $answers): array {
		$names = array_map(static fn (array $field): string => (string)($field['name'] ?? ''), $fields);
		$known = array_intersect_key($answers, array_flip($names));

		return $this->calculated(fields: $fields, answers: $known)['answers'];
	}//end knownAnswers()

	/**
	 * Work out the calculated fields and the decided ones for a submission.
	 *
	 * @param array<string, mixed> $render What render() returned for the form.
	 * @param array<string, mixed> $answers The validated answers.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>, decisions: array<string, string>}|null
	 *         Null when a decision the form declares could not be asked.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function workedOut(array $render, array $answers): ?array {
		$fields  = (array)($render['fields'] ?? []);
		$first   = $this->calculated(fields: $fields, answers: $answers);
		$decided = $this->decidedSteps(steps: (array)($render['steps'] ?? []), worked: $first);
		if ($decided === null) {
			return null;
		}

		if ($decided['decisions'] !== []) {
			// A calculation may read a decided field.
			$again = $this->calculated(fields: $fields, answers: $decided['answers']);
			$decided['answers']  = $again['answers'];
			$decided['computed'] = array_merge($decided['computed'], $again['computed']);
		}

		return ['answers' => $decided['answers'], 'computed' => array_values(array_unique($decided['computed'])), 'decisions' => $decided['decisions']];
	}//end workedOut()

	/**
	 * The calculated fields worked out over the answers; the answers as they
	 * are when there is no calculator.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $answers The answers.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>}
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	private function calculated(array $fields, array $answers): array {
		if ($this->calculator === null) {
			return ['answers' => $answers, 'computed' => []];
		}

		$applied = $this->calculator->apply(fields: $fields, answers: $answers);

		return ['answers' => $applied['answers'], 'computed' => $applied['computed']];
	}//end calculated()

	/**
	 * Ask each step that declares a decision, in order, and write its outcome
	 * into the answers.
	 *
	 * @param array<int, mixed>                                                       $steps  The form's steps.
	 * @param array{answers: array<string, mixed>, computed: array<int, string>} $worked The answers worked out so far.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>, decisions: array<string, string>}|null
	 *         Null when a decision could not be asked.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	private function decidedSteps(array $steps, array $worked): ?array {
		$worked['decisions'] = [];
		foreach ($steps as $step) {
			if (is_array($step['decision'] ?? null) === false) {
				continue;
			}

			$worked = $this->withDecision(step: $step, worked: $worked);
			if ($worked === null) {
				return null;
			}
		}

		return $worked;
	}//end decidedSteps()

	/**
	 * Ask the rule engine for one step's decision and record a decided outcome.
	 *
	 * @param array<string, mixed> $step   The step that declares the decision.
	 * @param array<string, mixed> $worked The answers, computed fields and decisions so far.
	 *
	 * @return array<string, mixed>|null The worked-out state, or null when the decision could not be asked.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	private function withDecision(array $step, array $worked): ?array {
		if ($this->decision === null) {
			return null;
		}

		$decided = $this->decision->decide(decision: $step['decision'], answers: $worked['answers']);
		if ($decided['status'] === PortalFormDecision::UNAVAILABLE) {
			return null;
		}

		if ($decided['status'] === PortalFormDecision::DECIDED) {
			$worked['answers'][$decided['output']]   = $decided['outcome'];
			$worked['decisions'][(string)$step['id']] = $decided['outcome'];
			$worked['computed'][] = $decided['output'];
		}

		return $worked;
	}//end withDecision()
}//end class
