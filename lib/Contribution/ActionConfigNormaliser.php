<?php

/**
 * Portaliq Action Configuration Normaliser (contribution-manifest-v3)
 *
 * The action half of the fail-closed v3 UI-configuration vocabulary (ADR-046 /
 * ADR-063): per-field form configs, the server-enforced `set` transition
 * target, the option providers (delegated), and the free-text labels.
 *
 * INVARIANT: this class NEVER adds a field to an action's `fields` whitelist.
 * The whitelist is a read-only input; every key it validates must already be
 * in it, which is where a "sneaked-in" field is dropped.
 *
 * WMEBV data-minimisation (~Awb 2:15): a `required: true` flag survives ONLY
 * for a field the action's SCHEMA genuinely mandates — never on a guess, never
 * on an unresolvable schema.
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
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\PortalSchemaReader;

/**
 * Validates and sanitises the v3 action form config, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
 */
class ActionConfigNormaliser {
	/**
	 * Allowed form-field sizes; anything else normalises to `medium`.
	 */
	private const FIELD_SIZES = ['small', 'medium', 'large', 'full'];

	/**
	 * Constructor.
	 *
	 * @param ManifestValueNormaliser $values The shared value-level primitives.
	 * @param ActionOptionsNormaliser $options The option-provider validator.
	 * @param PortalSchemaReader|null $schemaReader Resolves an action's schema
	 *                                              `required` set for the WMEBV
	 *                                              data-minimisation guard. A
	 *                                              null reader means the guard
	 *                                              always fails closed (drops
	 *                                              `required`).
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	public function __construct(
		private readonly ManifestValueNormaliser $values,
		private readonly ActionOptionsNormaliser $options,
		private readonly ?PortalSchemaReader $schemaReader = null,
	) {
	}//end __construct()

	/**
	 * Sanitise the form-configuration keys on every action.
	 *
	 * @param array<int, mixed> $actions The action list.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
	 */
	public function normaliseActions(array $actions): array {
		$out = [];
		foreach ($actions as $action) {
			if (is_array($action) === false) {
				continue;
			}

			$whitelist = [];
			if (isset($action['fields']) === true && is_array($action['fields']) === true) {
				$whitelist = array_values(array_filter($action['fields'], static fn ($f) => is_string($f) === true));
			}

			$mandatory = $this->mandatoryFields(action: $action);
			$action = $this->normaliseFieldConfigs(action: $action, whitelist: $whitelist, mandatory: $mandatory);
			$action = $this->options->normaliseOptionsProviders(action: $action, whitelist: $whitelist);
			$action = $this->normaliseSet(action: $action, whitelist: $whitelist);
			$action = $this->normaliseTextKeys(action: $action);
			$action = $this->values->normaliseAnonymousFlag(entry: $action);

			$out[] = $action;
		}//end foreach

		return $out;
	}//end normaliseActions()

	/**
	 * Drop a non-string `submitLabel` / `successMessage`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseTextKeys(array $action): array {
		foreach (['submitLabel', 'successMessage'] as $textKey) {
			if (isset($action[$textKey]) === true && is_string($action[$textKey]) === false) {
				unset($action[$textKey]);
			}
		}

		return $action;
	}//end normaliseTextKeys()

	/**
	 * Keep a well-formed `set` (server-enforced transition target): a map of
	 * WHITELISTED field → scalar value. A key outside the action's `fields` (or a
	 * non-scalar value) is dropped, so `set` can only ever fix a field the action
	 * is already entitled to write. The whole key is dropped when malformed.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields` whitelist.
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseSet(array $action, array $whitelist): array {
		if (array_key_exists('set', $action) === false) {
			return $action;
		}

		if (is_array($action['set']) === false) {
			unset($action['set']);
			return $action;
		}

		$set = [];
		foreach ($action['set'] as $field => $value) {
			if (in_array($field, $whitelist, true) === true && (is_scalar($value) === true || $value === null)) {
				$set[$field] = $value;
			}
		}

		$action['set'] = $set;
		return $action;
	}//end normaliseSet()

	/**
	 * Keep only `fieldConfigs` whose key is in the action's whitelist.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields` whitelist.
	 * @param array<int, string> $mandatory The action's schema `required` set
	 *                                      (empty when unresolvable — fails
	 *                                      closed, never elevated on a guess).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	private function normaliseFieldConfigs(array $action, array $whitelist, array $mandatory = []): array {
		if (array_key_exists('fieldConfigs', $action) === false) {
			return $action;
		}

		if (is_array($action['fieldConfigs']) === false) {
			unset($action['fieldConfigs']);
			return $action;
		}

		$configs = [];
		foreach ($action['fieldConfigs'] as $field => $config) {
			// A field config may only ever describe a whitelisted field — this is
			// where a "sneaked-in" field that is not in `fields` is dropped.
			if (in_array($field, $whitelist, true) === false || is_array($config) === false) {
				continue;
			}

			$configs[$field] = $this->fieldConfigEntry(field: (string)$field, config: $config, mandatory: $mandatory);
		}

		$action['fieldConfigs'] = $configs;
		return $action;
	}//end normaliseFieldConfigs()

	/**
	 * Build ONE sanitised field-config entry: the string labels, the boolean
	 * flags (WMEBV-guarded), and the size enum.
	 *
	 * @param string $field The whitelisted field name.
	 * @param array<string, mixed> $config The declared field config.
	 * @param array<int, string> $mandatory The action's schema `required` set.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	private function fieldConfigEntry(string $field, array $config, array $mandatory): array {
		$entry = [];
		foreach (['label', 'placeholder', 'help'] as $textKey) {
			if (isset($config[$textKey]) === true && is_string($config[$textKey]) === true) {
				$entry[$textKey] = $config[$textKey];
			}
		}

		$entry = $this->applyFieldFlags(entry: $entry, field: $field, config: $config, mandatory: $mandatory);
		$entry['size'] = $this->values->oneOf(value: ($config['size'] ?? null), allowed: self::FIELD_SIZES, default: 'medium');

		return $entry;
	}//end fieldConfigEntry()

	/**
	 * Coerce the declared `visible` / `required` / `disabled` flags onto an
	 * entry.
	 *
	 * WMEBV data-minimisation (~Awb 2:15, forms-may-not-require-non-mandatory-
	 * fields): a `required: true` flag is honoured ONLY when the field is also
	 * in `mandatory` (the action's schema `required` set) — otherwise it is
	 * dropped fail-closed and the field stays optional. An electronic form may
	 * never require a field the schema itself does not mandate.
	 *
	 * @param array<string, mixed> $entry The entry built so far.
	 * @param string $field The whitelisted field name.
	 * @param array<string, mixed> $config The declared field config.
	 * @param array<int, string> $mandatory The action's schema `required` set.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	private function applyFieldFlags(array $entry, string $field, array $config, array $mandatory): array {
		foreach (['visible', 'required', 'disabled'] as $flag) {
			if (isset($config[$flag]) === false) {
				continue;
			}

			$value = ($config[$flag] === true || $config[$flag] === 'true' || $config[$flag] === 1);

			// WMEBV guard: a `required: true` may only survive for a field
			// the action's schema genuinely mandates — never a non-
			// mandatory field, and never on an unresolvable schema.
			if ($flag === 'required' && $value === true && in_array($field, $mandatory, true) === false) {
				continue;
			}

			$entry[$flag] = $value;
		}//end foreach

		return $entry;
	}//end applyFieldFlags()

	/**
	 * Resolve an action's schema `required` set (the field names it genuinely
	 * mandates), or an empty set when unresolvable — fail-closed, per the
	 * WMEBV data-minimisation guard: a `required` flag is NEVER elevated on a
	 * guess. Requires a schema slug on the action AND an injected
	 * PortalSchemaReader; either being absent yields an empty set.
	 *
	 * @param array<string, mixed> $action The action (reads its `schema` key).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	private function mandatoryFields(array $action): array {
		if ($this->schemaReader === null) {
			return [];
		}

		$schemaSlug = ($action['schema'] ?? null);
		if (is_string($schemaSlug) === false || $schemaSlug === '') {
			return [];
		}

		$definition = $this->schemaReader->readSchema(slug: $schemaSlug);
		if ($definition === null) {
			return [];
		}

		$required = ($definition['required'] ?? null);
		if (is_array($required) === false) {
			return [];
		}

		return array_values(array_filter($required, static fn ($f) => is_string($f) === true));
	}//end mandatoryFields()
}//end class
