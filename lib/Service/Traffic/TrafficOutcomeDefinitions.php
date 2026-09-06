<?php

/**
 * Portaliq Traffic Outcome Definitions.
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
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Normalises what a portal wrote under `traffic.goals`, `traffic.funnels`
 * and `traffic.customDimensions` into what the aggregation and the
 * validator act on.
 *
 * A definition that cannot be acted on is DROPPED, not repaired: a goal
 * without an id, a step without a match, a dimension with an id that is
 * not a plain token. The admin form shows the record as written; the
 * resolved block the client and the job read shows only what counts. Ids
 * are bounded tokens because they become parameter keys (`cd_<id>`) and
 * rollup keys, and a rollup keyed by free text is a record that stops
 * fitting.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
 */
class TrafficOutcomeDefinitions {

	/**
	 * The scopes a custom dimension may have: counted once per session, or
	 * once per event.
	 *
	 * @var string[]
	 */
	public const DIMENSION_SCOPES = ['session', 'event'];

	/**
	 * The most goals, funnels or dimensions one portal may declare, and the
	 * most steps one funnel may have. Enough for any report; bounded so a
	 * record cannot make the aggregation quadratic.
	 */
	public const MAX_DEFINITIONS = 50;

	/**
	 * The most steps one funnel carries.
	 */
	public const MAX_STEPS = 12;

	/**
	 * The longest name kept.
	 */
	private const MAX_NAME = 128;

	/**
	 * Constructor.
	 *
	 * @param TrafficMatch $match The match normaliser.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficMatch $match = new TrafficMatch(),
	) {
	}

	/**
	 * The portal's goals, each `{id, name, type, match, value}`.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, mixed>> The usable goals.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function goals(mixed $value): array {
		$out = [];
		foreach ($this->rows(value: $value) as $row) {
			$id = $this->token(value: ($row['id'] ?? null));
			$type = (string)($row['type'] ?? '');
			$match = $this->match->normalise(value: ($row['match'] ?? null));
			if ($id === '' || isset(TrafficMatch::TYPES[$type]) === false || $match === null || isset($out[$id]) === true) {
				continue;
			}

			$out[$id] = [
				'id' => $id,
				'name' => $this->name(value: ($row['name'] ?? null), fallback: $id),
				'type' => $type,
				'match' => $match,
				'value' => $this->value(value: ($row['value'] ?? null)),
			];
		}

		return array_values($out);
	}

	/**
	 * The portal's funnels, each `{id, name, steps: [{name, match}]}` with
	 * at least one step.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, mixed>> The usable funnels.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
	 */
	public function funnels(mixed $value): array {
		$out = [];
		foreach ($this->rows(value: $value) as $row) {
			$id = $this->token(value: ($row['id'] ?? null));
			if ($id === '' || isset($out[$id]) === true) {
				continue;
			}

			$steps = [];
			foreach ($this->rows(value: ($row['steps'] ?? null), max: self::MAX_STEPS) as $index => $step) {
				$match = $this->match->normalise(value: ($step['match'] ?? null));
				if ($match === null) {
					continue;
				}

				$steps[] = [
					'name' => $this->name(value: ($step['name'] ?? null), fallback: 'Step ' . ($index + 1)),
					'match' => $match,
				];
			}

			if ($steps === []) {
				continue;
			}

			$out[$id] = ['id' => $id, 'name' => $this->name(value: ($row['name'] ?? null), fallback: $id), 'steps' => $steps];
		}

		return array_values($out);
	}

	/**
	 * The portal's custom dimensions, each `{id, name, scope}`.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, string>> The usable dimensions.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
	 */
	public function customDimensions(mixed $value): array {
		$out = [];
		foreach ($this->rows(value: $value) as $row) {
			$id = $this->token(value: ($row['id'] ?? null));
			if ($id === '' || isset($out[$id]) === true) {
				continue;
			}

			$scope = (string)($row['scope'] ?? 'event');
			if (in_array($scope, self::DIMENSION_SCOPES, true) === false) {
				$scope = 'event';
			}

			$out[$id] = ['id' => $id, 'name' => $this->name(value: ($row['name'] ?? null), fallback: $id), 'scope' => $scope];
		}

		return array_values($out);
	}

	/**
	 * The list's rows that are maps, bounded.
	 *
	 * @param mixed $value The configured list.
	 * @param int   $max   The most rows kept.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(mixed $value, int $max = self::MAX_DEFINITIONS): array {
		if (is_array($value) === false) {
			return [];
		}

		$rows = array_values(array_filter($value, static fn ($row): bool => is_array($row)));

		return array_slice($rows, 0, $max);
	}

	/**
	 * A bounded token id, or ''.
	 *
	 * @param mixed $value The configured id.
	 *
	 * @return string The id.
	 */
	private function token(mixed $value): string {
		if (is_string($value) === false || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
			return '';
		}

		return $value;
	}

	/**
	 * A bounded display name, falling back when none was given.
	 *
	 * @param mixed  $value    The configured name.
	 * @param string $fallback What to call it otherwise.
	 *
	 * @return string The name.
	 */
	private function name(mixed $value, string $fallback): string {
		if (is_string($value) === false || trim($value) === '') {
			return $fallback;
		}

		return mb_substr(trim($value), 0, self::MAX_NAME);
	}

	/**
	 * A goal's value: a non-negative number, else 0.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return float The value.
	 */
	private function value(mixed $value): float {
		if (is_int($value) === true || is_float($value) === true) {
			return max(0.0, (float)$value);
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return max(0.0, (float)$value);
		}

		return 0.0;
	}
}
