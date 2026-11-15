<?php

/**
 * Portaliq Form Steps Run (site-multi-step-forms, REQ-SMF-010)
 *
 * One pass over the steps a form or action declares: which steps stay,
 * which fields each holds, and the step that collects the rest.
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The steps of one FormStepsNormaliser::steps() call. Never leaves
 * FormStepsNormaliser.
 *
 * @internal
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
 */
final class FormStepsRun {
	/**
	 * The fields the kept steps hold so far.
	 *
	 * @var array<int, string>
	 */
	private array $placed = [];

	/**
	 * The kept steps that are not a review, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $kept = [];

	/**
	 * The kept review steps, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reviews = [];

	/**
	 * Cleans a step's title and description.
	 *
	 * @var FormText
	 */
	private readonly FormText $text;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $known The field names the form or action has, in order.
	 */
	public function __construct(
		private readonly array $known,
	) {
		$this->text = new FormText();
	}//end __construct()

	/**
	 * The kept steps, the loose step and at most one review last, or [] for a
	 * one-page form.
	 *
	 * @param array<int|string, mixed> $steps The declared steps.
	 *
	 * @return array<int, array<string, mixed>> The kept steps.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
	 */
	public function kept(array $steps): array {
		foreach (array_values($steps) as $index => $step) {
			$this->place(step: $step, index: $index);
		}

		if ($this->kept === []) {
			return [];
		}

		$loose = array_values(array_diff($this->known, $this->placed));
		if ($loose !== []) {
			$this->kept[] = ['id' => FormStepsNormaliser::LOOSE_STEP, 'title' => '', 'fields' => $loose];
		}

		return $this->withKnownTargets(steps: array_merge($this->kept, array_slice($this->reviews, 0, 1)));
	}//end kept()

	/**
	 * Keep one declared step as a review or as a step that holds fields.
	 *
	 * @param mixed $step  The declared step.
	 * @param int   $index Its place, for a missing id.
	 *
	 * @return void
	 */
	private function place(mixed $step, int $index): void {
		$clean = $this->step(step: $step, index: $index);
		if ($clean === null) {
			return;
		}

		if (($clean['review'] ?? false) === true) {
			$this->reviews[] = $clean;
			return;
		}

		$this->placed = array_merge($this->placed, $clean['fields']);
		$this->kept[] = $clean;
	}//end place()

	/**
	 * One clean step, or null when it is dropped: not an array, a review step
	 * that names fields, or a step naming a field the form does not know or a
	 * field an earlier step already holds.
	 *
	 * @param mixed $step  The declared step.
	 * @param int   $index Its place, for a missing id.
	 *
	 * @return array<string, mixed>|null The step.
	 */
	private function step(mixed $step, int $index): ?array {
		if (is_array($step) === false) {
			return null;
		}

		$fields = ($step['fields'] ?? []);
		if ($this->fieldsFit(fields: $fields, review: (($step['review'] ?? false) === true)) === false) {
			return null;
		}

		$clean = [
			'id'     => $this->stepId(value: ($step['id'] ?? null), index: $index),
			'title'  => $this->text->clean(value: ($step['title'] ?? null)),
			'fields' => array_values(array_unique($fields)),
		];

		return $this->withDetails(clean: $clean, step: $step);
	}//end step()

	/**
	 * A kept step with its description, its review mark, or the decision a
	 * step that is not the review declares.
	 *
	 * @param array<string, mixed> $clean The kept step so far.
	 * @param array<string, mixed> $step  The declared step.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function withDetails(array $clean, array $step): array {
		$description = $this->text->clean(value: ($step['description'] ?? null));
		if ($description !== '') {
			$clean['description'] = $description;
		}

		if (($step['review'] ?? false) === true) {
			$clean['review'] = true;
			return $clean;
		}

		$decision = (new FormStepDecision())->normalise(decision: ($step['decision'] ?? null), known: $this->known);
		if ($decision !== null) {
			$clean['decision'] = $decision;
		}

		return $clean;
	}//end withDetails()

	/**
	 * Whether a step's fields can be kept: a review names none, any other
	 * step names at least one, every one known and not held by an earlier step.
	 *
	 * @param mixed $fields The declared fields.
	 * @param bool  $review Whether the step is the review.
	 *
	 * @return bool True when the step may stay.
	 */
	private function fieldsFit(mixed $fields, bool $review): bool {
		if (is_array($fields) === false || $review !== ($fields === [])) {
			return false;
		}

		foreach ($fields as $field) {
			if ($this->placeable(field: $field) === false) {
				return false;
			}
		}

		return true;
	}//end fieldsFit()

	/**
	 * Whether a field is known and not held by an earlier step.
	 *
	 * @param mixed $field The declared field name.
	 *
	 * @return bool True when a step may hold it.
	 */
	private function placeable(mixed $field): bool {
		return is_string($field) === true
			&& in_array($field, $this->known, true) === true
			&& in_array($field, $this->placed, true) === false;
	}//end placeable()

	/**
	 * A step id: the declared one when it is a plain token, else `step-N`.
	 *
	 * @param mixed $value The declared id.
	 * @param int   $index The step's place.
	 *
	 * @return string The id.
	 */
	private function stepId(mixed $value, int $index): string {
		if (is_string($value) === true && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) === 1 && $value !== FormStepsNormaliser::LOOSE_STEP) {
			return $value;
		}

		return 'step-' . ($index + 1);
	}//end stepId()

	/**
	 * Keep a decision's `nextStep` entries only where they name a step the form has.
	 *
	 * @param array<int, array<string, mixed>> $steps The kept steps.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 */
	private function withKnownTargets(array $steps): array {
		$ids = array_column($steps, 'id');
		foreach ($steps as $index => $step) {
			if (isset($step['decision']) === true) {
				$steps[$index]['decision']['nextStep'] = array_filter(
					$step['decision']['nextStep'],
					static fn (string $target): bool => in_array($target, $ids, true)
				);
			}
		}

		return $steps;
	}//end withKnownTargets()
}//end class
