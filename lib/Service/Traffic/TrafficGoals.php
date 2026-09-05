<?php

/**
 * Portaliq Traffic Goals.
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
 * Evaluates a portal's goals over a day of sessions.
 *
 * TWO NUMBERS PER GOAL, BECAUSE THEY ANSWER TWO QUESTIONS. Conversions
 * count sessions that met the goal at least once: "how many visits ended
 * where we wanted". Completions count every matching event: "how often
 * did it happen". A visitor who downloads the same brochure three times is
 * one conversion and three completions, and a report that showed only one
 * of the two would overstate or understate depending on which.
 *
 * The value is the operator's number per conversion, so a goal worth 10
 * met by four sessions reads 40. What the unit is (euros, points, nothing)
 * is the operator's business; the rollup multiplies and does not judge.
 *
 * Pure: definitions and sessions in, rows out.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
 */
class TrafficGoals {

	/**
	 * Constructor.
	 *
	 * @param TrafficMatch $match Evaluates one match against one event.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficMatch $match = new TrafficMatch(),
	) {
	}

	/**
	 * The goal rows for the rollup, in the portal's order.
	 *
	 * @param array<int, array<string, mixed>> $goals    The resolved goal definitions.
	 * @param array<int, array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{id, name, conversions, completions, value}`.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function rows(array $goals, array $sessions): array {
		$out = [];
		foreach ($goals as $goal) {
			$conversions = 0;
			$completions = 0;
			foreach ($sessions as $session) {
				$hits = $this->completions(goal: $goal, session: $session);
				$completions += $hits;
				$conversions += (int)($hits > 0);
			}

			$out[] = [
				'id' => (string)$goal['id'],
				'name' => (string)$goal['name'],
				'conversions' => $conversions,
				'completions' => $completions,
				'value' => round(((float)($goal['value'] ?? 0)) * $conversions, 2),
			];
		}

		return $out;
	}

	/**
	 * The share of sessions that met at least one goal, three decimals.
	 *
	 * @param array<int, array<string, mixed>> $goals    The resolved goal definitions.
	 * @param array<int, array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return float Between 0 and 1; 0 with no sessions or no goals.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function conversionRate(array $goals, array $sessions): float {
		if ($goals === [] || $sessions === []) {
			return 0.0;
		}

		$converted = 0;
		foreach ($sessions as $session) {
			foreach ($goals as $goal) {
				if ($this->completions(goal: $goal, session: $session) > 0) {
					$converted++;
					break;
				}
			}
		}

		return round($converted / count($sessions), 3);
	}

	/**
	 * How many of a session's events meet a goal.
	 *
	 * @param array<string, mixed> $goal    The goal definition.
	 * @param array<string, mixed> $session The session.
	 *
	 * @return int The matching events.
	 */
	private function completions(array $goal, array $session): int {
		$hits = 0;
		foreach (($session['events'] ?? []) as $event) {
			if ($this->match->matches(match: $goal['match'], event: $event, type: (string)$goal['type']) === true) {
				$hits++;
			}
		}

		return $hits;
	}
}
