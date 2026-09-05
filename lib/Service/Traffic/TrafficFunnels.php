<?php

/**
 * Portaliq Traffic Funnels.
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
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Evaluates a portal's funnels over a day of sessions.
 *
 * ORDER IS THE WHOLE POINT. A funnel is a claim about a path: the visitor
 * saw the campaign page, then opened the form, then submitted it. A
 * session that submitted the form and afterwards wandered to the campaign
 * page did not walk that path, so it counts for the first step only. The
 * walk is over the session's events in journey order (the sessioniser's
 * ordering, by sequence or by clock), and a step is looked for only in
 * the events AFTER the one that satisfied the previous step.
 *
 * The drop-off of step N is the share of step N-1's sessions that never
 * reached N. The first step has none: there is nothing before it to drop
 * from.
 *
 * Pure: definitions and sessions in, rows out.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
 */
class TrafficFunnels {

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
	 * The funnel rows for the rollup, in the portal's order.
	 *
	 * @param array<int, array<string, mixed>> $funnels  The resolved funnel definitions.
	 * @param array<int, array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{id, name, steps: [{name, sessions, dropOff}]}`.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
	 */
	public function rows(array $funnels, array $sessions): array {
		$out = [];
		foreach ($funnels as $funnel) {
			$reached = array_fill(0, count($funnel['steps']), 0);
			foreach ($sessions as $session) {
				$depth = $this->depth(steps: $funnel['steps'], session: $session);
				for ($i = 0; $i < $depth; $i++) {
					$reached[$i]++;
				}
			}

			$steps = [];
			$previous = null;
			foreach ($funnel['steps'] as $index => $step) {
				$steps[] = [
					'name' => (string)$step['name'],
					'sessions' => $reached[$index],
					'dropOff' => $this->dropOff(previous: $previous, current: $reached[$index]),
				];
				$previous = $reached[$index];
			}

			$out[] = ['id' => (string)$funnel['id'], 'name' => (string)$funnel['name'], 'steps' => $steps];
		}

		return $out;
	}

	/**
	 * How many steps, from the first, a session walked in order.
	 *
	 * @param array<int, array<string, mixed>> $steps   The funnel's steps.
	 * @param array<string, mixed>             $session The session.
	 *
	 * @return int 0 when it never reached the first step.
	 */
	private function depth(array $steps, array $session): int {
		$depth = 0;
		$events = $session['events'] ?? [];
		$total = count($events);
		$from = 0;
		foreach ($steps as $step) {
			$found = false;
			for ($i = $from; $i < $total; $i++) {
				if ($this->match->matches(match: $step['match'], event: $events[$i]) === true) {
					$found = true;
					$from = $i + 1;
					break;
				}
			}

			if ($found === false) {
				break;
			}

			$depth++;
		}

		return $depth;
	}

	/**
	 * The share of the previous step's sessions that did not reach this
	 * one, three decimals; 0 for the first step or an empty previous one.
	 *
	 * @param int|null $previous Sessions at the previous step, null for the first.
	 * @param int      $current  Sessions at this step.
	 *
	 * @return float Between 0 and 1.
	 */
	private function dropOff(?int $previous, int $current): float {
		if ($previous === null || $previous <= 0) {
			return 0.0;
		}

		return round(($previous - $current) / $previous, 3);
	}
}
