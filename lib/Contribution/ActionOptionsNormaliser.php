<?php

/**
 * Portaliq Action Options-Provider Normaliser (contribution-manifest-v3)
 *
 * Validates the `optionsProviders` half of an action's v3 form configuration:
 * the `static` (inline value/label pairs) and `collection` (subject-scoped
 * lookup) provider kinds. Anything else — an unknown kind, an unwhitelisted
 * field, a malformed provider — is dropped fail-closed.
 *
 * A `collection` provider is populated by the frontend through the
 * SUBJECT-SCOPED collection endpoint, so it can only ever offer values the
 * subject may already read; this class therefore never widens data access.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates an action's option providers, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 */
class ActionOptionsNormaliser {
	/**
	 * Allowed option-provider kinds.
	 */
	private const PROVIDER_KINDS = ['static', 'collection'];

	/**
	 * Constructor.
	 *
	 * @param ManifestValueNormaliser $values The shared value-level primitives.
	 */
	public function __construct(
		private readonly ManifestValueNormaliser $values,
	) {
	}//end __construct()

	/**
	 * Keep only valid `static` / `collection` option providers.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string> $whitelist The action's `fields` whitelist.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
	 */
	public function normaliseOptionsProviders(array $action, array $whitelist): array {
		if (array_key_exists('optionsProviders', $action) === false) {
			return $action;
		}

		if (is_array($action['optionsProviders']) === false) {
			unset($action['optionsProviders']);
			return $action;
		}

		$providers = [];
		foreach ($action['optionsProviders'] as $field => $provider) {
			if (in_array($field, $whitelist, true) === false || is_array($provider) === false) {
				continue;
			}

			$normalised = $this->normaliseProvider(provider: $provider);
			if ($normalised !== null) {
				$providers[$field] = $normalised;
			}
		}//end foreach

		$action['optionsProviders'] = $providers;
		return $action;
	}//end normaliseOptionsProviders()

	/**
	 * Dispatch ONE provider to its kind-specific validator, or null when the
	 * declared kind is not in the registry.
	 *
	 * @param array<string, mixed> $provider The provider config.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseProvider(array $provider): ?array {
		$kind = $this->values->oneOf(value: ($provider['type'] ?? null), allowed: self::PROVIDER_KINDS, default: '');
		if ($kind === 'static') {
			return $this->normaliseStaticProvider(provider: $provider);
		}

		if ($kind === 'collection') {
			return $this->normaliseCollectionProvider(provider: $provider);
		}

		return null;
	}//end normaliseProvider()

	/**
	 * Validate a `static` option provider.
	 *
	 * @param array<string, mixed> $provider The provider config.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseStaticProvider(array $provider): ?array {
		if (is_array($provider['options'] ?? null) === false) {
			return null;
		}

		$options = [];
		foreach ($provider['options'] as $option) {
			if (is_array($option) === false) {
				continue;
			}

			$value = ($option['value'] ?? null);
			$label = ($option['label'] ?? null);
			if ((is_string($value) === true || is_int($value) === true) && is_string($label) === true) {
				$options[] = ['value' => (string)$value, 'label' => $label];
			}
		}

		if ($options === []) {
			return null;
		}

		return ['type' => 'static', 'options' => $options];
	}//end normaliseStaticProvider()

	/**
	 * Validate a `collection` option provider. The dropdown is populated by the
	 * frontend through the SUBJECT-SCOPED collection endpoint, so it can only
	 * ever offer values the subject may already read.
	 *
	 * @param array<string, mixed> $provider The provider config.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseCollectionProvider(array $provider): ?array {
		$keys = ['register', 'schema', 'labelField', 'valueField'];
		$out = ['type' => 'collection'];
		foreach ($keys as $key) {
			$value = ($provider[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				return null;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end normaliseCollectionProvider()
}//end class
