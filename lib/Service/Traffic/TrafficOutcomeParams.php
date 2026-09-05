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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
 */
class TrafficOutcomeParams {

	/**
	 * Constructor.
	 *
	 * @param TrafficExperiments $experiments Says which experiment tags name a running experiment.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficExperiments $experiments = new TrafficExperiments(),
	) {
	}

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
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
	 */
	public function filter(string $name, array $params, array $config): array {
		$declared = $this->declared(config: $config);
		$formEvent = in_array($name, TrafficConfigResolver::FORM_EVENTS, true);
		$heatEvent = in_array($name, TrafficConfigResolver::HEAT_EVENTS, true);
		$tagged = $this->tagged(params: $params, config: $config);
		$out = [];
		foreach ($params as $key => $value) {
			$kept = $this->kept(key: $key, value: $value, rules: ['declared' => $declared, 'tagged' => $tagged, 'form' => $formEvent, 'heat' => $heatEvent]);
			if ($kept !== null) {
				$out[$key] = $kept;
			}
		}

		return $out;
	}

	/**
	 * One param as stored, or null to drop it.
	 *
	 * @param string                $key   The param key.
	 * @param string|int|float|bool $value The bounded value.
	 * @param array<string, mixed>  $rules `declared` (the custom dimension keys), `tagged`, `form` and `heat`.
	 *
	 * @return string|int|float|bool|null The value to store.
	 */
	private function kept(string $key, string|int|float|bool $value, array $rules): string|int|float|bool|null {
		if (str_starts_with($key, TrafficConfigResolver::CUSTOM_DIMENSION_PREFIX) === true) {
			if (isset($rules['declared'][$key]) === true) {
				return $value;
			}

			return null;
		}

		if (in_array($key, TrafficConfigResolver::EXPERIMENT_PARAMS, true) === true) {
			if ($rules['tagged'] === true) {
				return $value;
			}

			return null;
		}

		if ($rules['form'] === true && in_array($key, TrafficConfigResolver::FORM_EVENT_PARAMS, true) === false) {
			return null;
		}

		if ($rules['heat'] === true) {
			return $this->heat(key: $key, value: $value);
		}

		return $value;
	}

	/**
	 * Whether the params name a running experiment and one of its
	 * variants. Half a tag, or a tag for an experiment that is not
	 * running, is stripped whole: a session tagged with a stopped
	 * experiment would be counted for a result that is already final.
	 *
	 * @param array<string, string|int|float|bool> $params The bounded params.
	 * @param array<string, mixed>                 $config The resolved configuration.
	 *
	 * @return bool True when both tags may be stored.
	 */
	private function tagged(array $params, array $config): bool {
		$experiment = $params['experiment'] ?? null;
		$variant = $params['variant'] ?? null;
		if (is_string($experiment) === false || is_string($variant) === false) {
			return false;
		}

		return $this->experiments->acceptsTag(config: $config, experiment: $experiment, variant: $variant);
	}

	/**
	 * One heatmap param as stored, or null to drop it: only the listed
	 * keys, the fractions as numbers between 0 and 1, the selector without
	 * anything that looks like an id.
	 *
	 * @param string                    $key   The param key.
	 * @param string|int|float|bool     $value The bounded value.
	 *
	 * @return string|int|float|null The value to store.
	 */
	private function heat(string $key, string|int|float|bool $value): string|int|float|null {
		if (in_array($key, TrafficConfigResolver::HEAT_EVENT_PARAMS, true) === false) {
			return null;
		}

		if ($key === 'x' || $key === 'y' || $key === 'depth') {
			return $this->fraction(value: $value);
		}

		if ($key === 'vw') {
			return (int)$value;
		}

		if ($key === 'selector') {
			// Tags and classes only: an id fragment or an attribute selector
			// is where a name or a number ends up in a selector.
			$clean = (string)preg_replace('/#[^\s>.]+|\[[^\]]*\]/', '', (string)$value);

			return mb_substr(trim($clean), 0, 128);
		}

		return mb_substr(strtolower((string)$value), 0, 32);
	}

	/**
	 * A fraction of the page, four decimals, or null when it is not one.
	 *
	 * @param string|int|float|bool $value The bounded value.
	 *
	 * @return float|null The fraction.
	 */
	private function fraction(string|int|float|bool $value): ?float {
		if (is_numeric($value) === false || (float)$value < 0.0 || (float)$value > 1.0) {
			return null;
		}

		return round((float)$value, 4);
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
