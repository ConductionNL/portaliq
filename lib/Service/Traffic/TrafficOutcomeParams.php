<?php

/**
 * Portaliq Traffic Outcome Params.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\TrafficConfigResolver;

/**
 * The two param rules the outcomes add to the validator
 * (portal-traffic-outcomes), applied after the params were bounded.
 *
 * A form event keeps only the whitelisted keys, so a value typed into a
 * field cannot reach storage even from a client that sends it. A custom
 * dimension (`cd_<id>`) is kept only when the portal declared the id,
 * which is the contract's rule for dimensions applied to these. Both
 * STRIP, never refuse: the event is still a real start, field or view.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
 */
class TrafficOutcomeParams {

	/**
	 * The params to store.
	 *
	 * @param string                               $name   The event name.
	 * @param array<string, string|int|float|bool> $params The bounded params.
	 * @param array<string, mixed>                 $config The resolved configuration.
	 *
	 * @return array<string, string|int|float|bool> The params that survive.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
	 */
	public function filter(string $name, array $params, array $config): array {
		$declared = $this->declared(config: $config);
		$formEvent = in_array($name, TrafficConfigResolver::FORM_EVENTS, true);
		$out = [];
		foreach ($params as $key => $value) {
			if (str_starts_with($key, TrafficConfigResolver::CUSTOM_DIMENSION_PREFIX) === true) {
				if (isset($declared[$key]) === true) {
					$out[$key] = $value;
				}

				continue;
			}

			if ($formEvent === true && in_array($key, TrafficConfigResolver::FORM_EVENT_PARAMS, true) === false) {
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}

	/**
	 * The declared custom dimension params, as `cd_<id>` keys.
	 *
	 * @param array<string, mixed> $config The resolved configuration.
	 *
	 * @return array<string, bool> The keys.
	 */
	private function declared(array $config): array {
		$declared = [];
		foreach (($config['customDimensions'] ?? []) as $dimension) {
			if (is_array($dimension) === false) {
				continue;
			}

			$declared[TrafficConfigResolver::CUSTOM_DIMENSION_PREFIX . (string)($dimension['id'] ?? '')] = true;
		}

		return $declared;
	}
}
