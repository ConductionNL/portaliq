<?php

/**
 * Portaliq Traffic First Event Tally.
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
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Counts sessions per tuple of values read off each session's first
 * event, for TrafficDimensionCounts::perSession(). Pure.
 *
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */
final class TrafficFirstEventTally {

	/**
	 * The sessions counted per tuple of first-event values, in order of
	 * first appearance; a session whose values are all empty is skipped.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param array<int, string>               $keys     The event fields to read.
	 *
	 * @return array<string, array{values: array<int, string>, count: int}> The tally, by tuple.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function tally(array $sessions, array $keys): array {
		$tally = [];
		foreach ($sessions as $session) {
			$values = $this->firstEventValues(session: $session, keys: $keys);
			if ($values === null) {
				continue;
			}

			$id = implode("\0", $values);
			$tally[$id] = ['values' => $values, 'count' => (($tally[$id]['count'] ?? 0) + 1)];
		}

		return $tally;
	}

	/**
	 * The trimmed values of some fields of a session's first event, or null
	 * when every one of them is empty.
	 *
	 * @param array<string, mixed> $session The session.
	 * @param array<int, string>   $keys    The event fields to read.
	 *
	 * @return array<int, string>|null The values, in key order.
	 */
	private function firstEventValues(array $session, array $keys): ?array {
		$first = $session['events'][0] ?? [];
		$values = [];
		$any = false;
		foreach ($keys as $key) {
			$value = trim((string)($first[$key] ?? ''));
			$any = $any || ($value !== '');
			$values[] = $value;
		}

		if ($any === false) {
			return null;
		}

		return $values;
	}
}
