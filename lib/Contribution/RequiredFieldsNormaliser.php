<?php

/**
 * Portaliq Required Fields Normaliser (site-multi-step-forms, REQ-SMF-023)
 *
 * Decides, once per action, which of its fields a resident must fill in, and
 * writes that as `fieldConfigs.<field>.required: true`, the one flag the site
 * and the server guard both read.
 *
 * A field is required when it is one of the action's own `fields` AND either
 * - the action's schema requires it, on a create action (a schema-required
 *   field can never read as optional), or
 * - the action names it in `requiredFields` (Ruben, 3 October 2026: "Let an
 *   action name its required fields").
 *
 * Data minimisation holds because the set can only narrow what the action
 * already asks for: a name outside `fields` is dropped, so an action can
 * never require (or thereby collect) a field it does not send. A file field
 * is dropped too: it is uploaded after the record exists, so the create body
 * never holds it and the server could not check it. A field the server fills
 * itself (a `defaults` key, the `subjectField`) is never asked of the
 * resident, so it is never marked either.
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-an-action-may-name-its-required-fields-req-smf-023
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises `requiredFields` and marks the effective required set.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-an-action-may-name-its-required-fields-req-smf-023
 */
class RequiredFieldsNormaliser {
	/**
	 * The action key that names the extra required fields.
	 */
	public const KEY = 'requiredFields';

	/**
	 * Keep a sound `requiredFields` list and mark every required field.
	 *
	 * @param array<string, mixed> $action The action, after its field configs.
	 * @param array<int, string> $whitelist The action's `fields`.
	 * @param array<int, string> $mandatory The action's schema `required` set.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-an-action-may-name-its-required-fields-req-smf-023
	 */
	public function apply(array $action, array $whitelist, array $mandatory): array {
		$configs = ($action['fieldConfigs'] ?? []);
		if (is_array($configs) === false) {
			$configs = [];
		}

		$askable = array_values(array_diff($whitelist, $this->serverFilled(action: $action)));
		$named   = $this->named(value: ($action[self::KEY] ?? null), whitelist: $askable, configs: $configs);
		unset($action[self::KEY]);
		if ($named !== []) {
			$action[self::KEY] = $named;
		}

		$required = $named;
		if (($action['type'] ?? '') === 'create') {
			$required = array_merge($required, $this->schemaRequired(askable: $askable, mandatory: $mandatory, configs: $configs));
		}

		foreach (array_unique($required) as $field) {
			$entry = ($configs[$field] ?? ['size' => 'medium']);
			$entry['required'] = true;
			$configs[$field]   = $entry;
		}

		if ($configs !== [] || array_key_exists('fieldConfigs', $action) === true) {
			$action['fieldConfigs'] = $configs;
		}

		return $action;
	}//end apply()

	/**
	 * The declared names that are the action's own, non-file fields, once each.
	 *
	 * @param mixed $value The declared `requiredFields`.
	 * @param array<int, string> $whitelist The action's `fields`.
	 * @param array<string, mixed> $configs The action's field configs.
	 *
	 * @return array<int, string> The names, in declared order.
	 */
	private function named(mixed $value, array $whitelist, array $configs): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $field) {
			if (is_string($field) === false
				|| in_array($field, $whitelist, true) === false
				|| in_array($field, $out, true) === true
				|| $this->isFileField(configs: $configs, field: $field) === true
			) {
				continue;
			}

			$out[] = $field;
		}

		return $out;
	}//end named()

	/**
	 * The asked fields the schema requires, without file fields.
	 *
	 * @param array<int, string> $askable The fields the resident is asked for.
	 * @param array<int, string> $mandatory The schema's `required` set.
	 * @param array<string, mixed> $configs The action's field configs.
	 *
	 * @return array<int, string> The fields.
	 */
	private function schemaRequired(array $askable, array $mandatory, array $configs): array {
		return array_values(
			array_filter(
				$askable,
				fn (string $field): bool => in_array($field, $mandatory, true) === true && $this->isFileField(configs: $configs, field: $field) === false
			)
		);
	}//end schemaRequired()

	/**
	 * The fields the server fills itself: the `defaults` keys and the
	 * `subjectField`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<int, string> The field names.
	 */
	private function serverFilled(array $action): array {
		$filled = [];
		if (is_array($action['defaults'] ?? null) === true) {
			$filled = array_map('strval', array_keys($action['defaults']));
		}

		if (is_string($action['subjectField'] ?? null) === true) {
			$filled[] = $action['subjectField'];
		}

		return $filled;
	}//end serverFilled()

	/**
	 * Whether a field is a file field.
	 *
	 * @param array<string, mixed> $configs The action's field configs.
	 * @param string $field The field.
	 *
	 * @return bool True for a file field.
	 */
	private function isFileField(array $configs, string $field): bool {
		return (($configs[$field]['type'] ?? null) === FileFieldConfigNormaliser::TYPE_FILE);
	}//end isFileField()
}//end class
