<?php

/**
 * Portaliq Form Steps Normaliser (site-multi-step-forms, REQ-SMF-010, -020, -022)
 *
 * One shape for the steps of a published form and of a create or endpoint
 * action: `[{ id, title, description?, fields[], review? }]`, the shape
 * `CnFormPage` and published forms already use. A step is kept only when
 * every field it names is one the form or action knows; a field a dropped
 * step named, or that no step names, goes in a last step of its own, before
 * the review. A step with `review: true` carries no fields. Without any kept
 * step with fields there are no steps, and the form renders as one page.
 *
 * The same class keeps an action's `draft` (`{ retentionDays }`, 1 to 90)
 * and `confirmation` (`{ title, body?, next? }`, text only).
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises steps, a draft declaration and a confirmation.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
 */
class FormStepsNormaliser {
	/**
	 * The id of the step that collects fields no kept step names.
	 */
	public const LOOSE_STEP = 'more';

	/**
	 * The longest title, description or confirmation text kept.
	 */
	private const MAX_TEXT = 2000;

	/**
	 * Keep `steps`, `draft` and `confirmation` on a create action or on an
	 * endpoint action with `fields`, sanitised; drop them anywhere else.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields`.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
	 */
	public function applyToAction(array $action, array $whitelist): array {
		$flows = (($action['type'] ?? '') === 'create')
			|| (isset($action['endpoint']) === true && $whitelist !== []);
		$declared = [
			'steps'        => ($action['steps'] ?? null),
			'draft'        => ($action['draft'] ?? null),
			'confirmation' => ($action['confirmation'] ?? null),
			'summary'      => ($action['summary'] ?? null),
		];
		unset($action['steps'], $action['draft'], $action['confirmation'], $action['summary']);
		if ($flows === false) {
			return $action;
		}

		$clean = [
			'steps'        => $this->steps(steps: $declared['steps'], known: $whitelist),
			'draft'        => $this->draft(draft: $declared['draft']),
			'confirmation' => $this->confirmation(confirmation: $declared['confirmation']),
			// One sentence from the answers (action-summary-sentence).
			'summary'      => (new ActionSummaryNormaliser())->summary(summary: $declared['summary'], whitelist: $whitelist),
		];
		foreach ($clean as $key => $value) {
			if ($value !== null && $value !== []) {
				$action[$key] = $value;
			}
		}

		return $action;
	}//end applyToAction()

	/**
	 * The steps of a form or action whose fields are `$known`.
	 *
	 * @param mixed $steps The declared steps.
	 * @param array<int, string> $known The field names the form or action has, in order.
	 *
	 * @return array<int, array<string, mixed>> The kept steps, or [] for a one-page form.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
	 */
	public function steps(mixed $steps, array $known): array {
		if (is_array($steps) === false) {
			return [];
		}

		$kept    = [];
		$placed  = [];
		$reviews = [];
		foreach (array_values($steps) as $index => $step) {
			$clean = $this->step(step: $step, index: $index, known: $known, placed: $placed);
			if ($clean === null) {
				continue;
			}

			if (($clean['review'] ?? false) === true) {
				$reviews[] = $clean;
				continue;
			}

			$placed = array_merge($placed, $clean['fields']);
			$kept[] = $clean;
		}

		if ($kept === []) {
			return [];
		}

		$loose = array_values(array_diff($known, $placed));
		if ($loose !== []) {
			$kept[] = ['id' => self::LOOSE_STEP, 'title' => '', 'fields' => $loose];
		}

		$all = array_merge($kept, array_slice($reviews, 0, 1));

		return $this->withKnownTargets(steps: $all);
	}//end steps()

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

	/**
	 * An action's draft declaration: `{ retentionDays }` clamped to 1 to 90.
	 *
	 * @param mixed $draft The declared draft.
	 *
	 * @return array{retentionDays: int}|null The draft, or null to drop it.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	public function draft(mixed $draft): ?array {
		if (is_array($draft) === false || is_int($draft['retentionDays'] ?? null) === false) {
			return null;
		}

		return ['retentionDays' => max(1, min(90, $draft['retentionDays']))];
	}//end draft()

	/**
	 * An action's confirmation: a title, and optionally a body and next steps,
	 * text only.
	 *
	 * @param mixed $confirmation The declared confirmation.
	 *
	 * @return array<string, string>|null The confirmation, or null to drop it.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-word-its-own-confirmation-req-smf-022
	 */
	public function confirmation(mixed $confirmation): ?array {
		if (is_array($confirmation) === false) {
			return null;
		}

		$out = [];
		foreach (['title', 'body', 'next'] as $key) {
			$text = $this->text(value: ($confirmation[$key] ?? null));
			if ($text !== '') {
				$out[$key] = $text;
			}
		}

		if (isset($out['title']) === false) {
			return null;
		}

		return $out;
	}//end confirmation()

	/**
	 * One clean step, or null when it is dropped: not an array, a review step
	 * that names fields, or a step naming a field the form does not know or a
	 * field an earlier step already holds.
	 *
	 * @param mixed $step The declared step.
	 * @param int $index Its place, for a missing id.
	 * @param array<int, string> $known The known field names.
	 * @param array<int, string> $placed The fields earlier steps hold.
	 *
	 * @return array<string, mixed>|null The step.
	 */
	private function step(mixed $step, int $index, array $known, array $placed): ?array {
		if (is_array($step) === false) {
			return null;
		}

		$fields = ($step['fields'] ?? []);
		$review = (($step['review'] ?? false) === true);
		if ($this->fieldsFit(fields: $fields, review: $review, known: $known, placed: $placed) === false) {
			return null;
		}

		$clean = [
			'id'     => $this->stepId(value: ($step['id'] ?? null), index: $index),
			'title'  => $this->text(value: ($step['title'] ?? null)),
			'fields' => array_values(array_unique($fields)),
		];
		$description = $this->text(value: ($step['description'] ?? null));
		if ($description !== '') {
			$clean['description'] = $description;
		}

		if ($review === true) {
			$clean['review'] = true;
		}

		$decision = (new FormStepDecision())->normalise(decision: ($step['decision'] ?? null), known: $known);
		if ($decision !== null && $review === false) {
			$clean['decision'] = $decision;
		}

		return $clean;
	}//end step()

	/**
	 * Whether a step's fields can be kept: a review names none, any other
	 * step names at least one, every one known and not held by an earlier step.
	 *
	 * @param mixed $fields The declared fields.
	 * @param bool $review Whether the step is the review.
	 * @param array<int, string> $known The known field names.
	 * @param array<int, string> $placed The fields earlier steps hold.
	 *
	 * @return bool True when the step may stay.
	 */
	private function fieldsFit(mixed $fields, bool $review, array $known, array $placed): bool {
		if (is_array($fields) === false || $review !== ($fields === [])) {
			return false;
		}

		foreach ($fields as $field) {
			if (is_string($field) === false || in_array($field, $known, true) === false || in_array($field, $placed, true) === true) {
				return false;
			}
		}

		return true;
	}//end fieldsFit()

	/**
	 * A step id: the declared one when it is a plain token, else `step-N`.
	 *
	 * @param mixed $value The declared id.
	 * @param int $index The step's place.
	 *
	 * @return string The id.
	 */
	private function stepId(mixed $value, int $index): string {
		if (is_string($value) === true && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) === 1 && $value !== self::LOOSE_STEP) {
			return $value;
		}

		return 'step-' . ($index + 1);
	}//end stepId()

	/**
	 * A trimmed text, or '' when it is not text or too long.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false || mb_strlen($value) > self::MAX_TEXT) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
