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

use OCA\Portaliq\Kernel\Contracts\FormStepsNormaliserInterface;

/**
 * Sanitises steps, a draft declaration and a confirmation.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
 */
class FormStepsNormaliser implements FormStepsNormaliserInterface {
	/**
	 * The id of the step that collects fields no kept step names.
	 */
	public const LOOSE_STEP = 'more';

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
		$flows    = $this->flows(action: $action, whitelist: $whitelist);
		$declared = $this->declared(action: $action);
		$action   = $this->withoutDeclarations(action: $action);
		if ($flows === false) {
			return $action;
		}

		return $this->withClean(action: $action, clean: $this->cleaned(declared: $declared, whitelist: $whitelist));
	}//end applyToAction()

	/**
	 * Whether the action runs as a form: a create action, or an endpoint
	 * action with `fields`.
	 *
	 * @param array<string, mixed> $action    The action.
	 * @param array<int, string>   $whitelist The action's `fields`.
	 *
	 * @return bool True when steps, a draft and a confirmation may stay.
	 */
	private function flows(array $action, array $whitelist): bool {
		return (($action['type'] ?? '') === 'create')
			|| (isset($action['endpoint']) === true && $whitelist !== []);
	}//end flows()

	/**
	 * The form declarations as the action carries them.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array{steps: mixed, draft: mixed, confirmation: mixed, answerSummary: mixed}
	 */
	private function declared(array $action): array {
		return [
			'steps'         => ($action['steps'] ?? null),
			'draft'         => ($action['draft'] ?? null),
			'confirmation'  => ($action['confirmation'] ?? null),
			'answerSummary' => $this->declaredAnswerSummary(action: $action),
		];
	}//end declared()

	/**
	 * The action without its form declarations.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function withoutDeclarations(array $action): array {
		unset($action['steps'], $action['draft'], $action['confirmation'], $action['answerSummary']);
		// `summary` is the start tile's string now (decision 127); an object
		// there is the old sentence shape, read above as `answerSummary`.
		if (is_array(($action['summary'] ?? null)) === true) {
			unset($action['summary']);
		}

		return $action;
	}//end withoutDeclarations()

	/**
	 * The declarations sanitised.
	 *
	 * @param array<string, mixed> $declared  The declarations as the action carries them.
	 * @param array<int, string>   $whitelist The action's `fields`.
	 *
	 * @return array<string, mixed> The clean declarations; null or [] where one is dropped.
	 */
	private function cleaned(array $declared, array $whitelist): array {
		return [
			'steps'         => $this->steps(steps: $declared['steps'], known: $whitelist),
			'draft'         => $this->draft(draft: $declared['draft']),
			'confirmation'  => $this->confirmation(confirmation: $declared['confirmation']),
			// One sentence from the answers (action-summary-sentence).
			'answerSummary' => (new ActionSummaryNormaliser())->summary(summary: $declared['answerSummary'], whitelist: $whitelist),
		];
	}//end cleaned()

	/**
	 * The action with every clean declaration that is not dropped.
	 *
	 * @param array<string, mixed> $action The action without its declarations.
	 * @param array<string, mixed> $clean  The clean declarations.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function withClean(array $action, array $clean): array {
		foreach ($clean as $key => $value) {
			if ($value !== null && $value !== []) {
				$action[$key] = $value;
			}
		}

		return $action;
	}//end withClean()

	/**
	 * The answer sentence an action declares: `answerSummary`, or the object
	 * an app still declares under `summary` (the shape before decision 127).
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return mixed The declared sentence object, or null.
	 *
	 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
	 */
	private function declaredAnswerSummary(array $action): mixed {
		if (array_key_exists('answerSummary', $action) === true) {
			return $action['answerSummary'];
		}

		return ($action['summary'] ?? null);
	}//end declaredAnswerSummary()

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

		return (new FormStepsRun(known: $known))->kept(steps: $steps);
	}//end steps()

	/**
	 * An action's draft declaration: `{ retentionDays }` clamped to 1 to 90.
	 *
	 * @param mixed $draft The declared draft.
	 *
	 * @return array{retentionDays: int}|null The draft, or null to drop it.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-action-must-be-the-record-itself-in-status-draft-and-only-its-residents-req-smf-021
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

		$out = $this->confirmationTexts(confirmation: $confirmation);
		if (isset($out['title']) === false) {
			return null;
		}

		return $out;
	}//end confirmation()

	/**
	 * The confirmation's title, body and next steps that are text.
	 *
	 * @param array<string, mixed> $confirmation The declared confirmation.
	 *
	 * @return array<string, string> The texts by key.
	 */
	private function confirmationTexts(array $confirmation): array {
		$text = new FormText();
		$out  = [];
		foreach (['title', 'body', 'next'] as $key) {
			$value = $text->clean(value: ($confirmation[$key] ?? null));
			if ($value !== '') {
				$out[$key] = $value;
			}
		}

		return $out;
	}//end confirmationTexts()
}//end class
