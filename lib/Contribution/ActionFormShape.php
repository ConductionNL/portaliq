<?php

/**
 * Portaliq Action Form Shape (site-multi-step-forms)
 *
 * The shape of the form an action draws, in one collaborator: which of its
 * fields the resident must fill in (RequiredFieldsNormaliser), how a field
 * asks its question (FieldWidgetNormaliser) and the steps the form runs in
 * with its draft and confirmation (FormStepsNormaliser).
 *
 * The three are one concern and they now arrive through one door, so
 * ActionConfigNormaliser keeps its dependencies countable: it had thirteen
 * and phpmd's CouplingBetweenObjects threshold is twelve.
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
 * The form-shape pass of an action: required fields, field widgets, steps.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
 */
class ActionFormShape {
	/**
	 * The input hints an action may declare on a field itself.
	 */
	private const FIELD_INPUTS = ['date', 'datetime', 'number', 'email'];

	/**
	 * The widget hint and the profile prefill source on ONE field config,
	 * copied from what the app declared.
	 *
	 * @param array<string, mixed> $entry The sanitised field config so far.
	 * @param array<string, mixed> $config The declared field config.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-action-field-may-start-from-the-signed-in-residents-name-or-e-mail-address
	 */
	public function fieldWidget(array $entry, array $config): array {
		$entry = (new ProfilePrefillKey())->apply(entry: $entry, source: $config);

		return (new FieldWidgetNormaliser())->apply(entry: $entry, source: $config);
	}//end fieldWidget()

	/**
	 * The input hint ONE field config declares itself, kept when it is one the
	 * site knows. A create action gets its hint from the schema's format
	 * (SchemaInputHintNormaliser), which runs later and wins; an endpoint
	 * action writes no schema here, so its own declaration is the only place
	 * a date field can say it is a date.
	 *
	 * @param array<string, mixed> $entry  The sanitised field config so far.
	 * @param array<string, mixed> $config The declared field config.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/site-forms/spec.md#requirement-an-endpoint-action-may-declare-a-fields-input-req-eaf-001
	 */
	public function declaredInput(array $entry, array $config): array {
		if (in_array(($config['input'] ?? null), self::FIELD_INPUTS, true) === true) {
			$entry['input'] = $config['input'];
		}

		return $entry;
	}//end declaredInput()

	/**
	 * Mark the fields the resident must fill in: the action's own
	 * `requiredFields`, plus the schema's required fields on a create.
	 *
	 * @param array<string, mixed> $action The action, after its field configs.
	 * @param array<int, string> $whitelist The action's `fields`.
	 * @param array<int, string> $mandatory The schema's `required` set.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-an-action-may-name-its-required-fields-req-smf-023
	 */
	public function requiredFields(array $action, array $whitelist, array $mandatory): array {
		return (new RequiredFieldsNormaliser())->apply(action: $action, whitelist: $whitelist, mandatory: $mandatory);
	}//end requiredFields()

	/**
	 * Drop a widget hint that does not fit its field, now that the options
	 * and the input hints are known.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
	 */
	public function fitWidgets(array $action): array {
		return (new FieldWidgetNormaliser())->reconcile(action: $action);
	}//end fitWidgets()

	/**
	 * Keep the steps, the draft and the confirmation of a create action or of
	 * an endpoint action with `fields`.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields`.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
	 */
	public function flow(array $action, array $whitelist): array {
		return (new FormStepsNormaliser())->applyToAction(action: $action, whitelist: $whitelist);
	}//end flow()

	/**
	 * Normalise the confirmation-mail keys and the row-action inputs of an
	 * action.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields`.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
	 */
	public function mailAndRowInputs(array $action, array $whitelist): array {
		$action = (new ConfirmationMailKeys())->normalise(action: $action, whitelist: $whitelist);

		return (new RowActionInputs())->normaliseAction(action: $action);
	}//end mailAndRowInputs()

	/**
	 * Normalise the life-domain keys of an action.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
	 */
	public function themeTags(array $action): array {
		return (new ThemeTagKeys())->action(action: $action);
	}//end themeTags()
}//end class
