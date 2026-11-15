<?php

/**
 * Portaliq Traffic Sessioniser.
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
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Turns a day of raw events into ordered sessions.
 *
 * Pure: no clock, no storage, no platform. The aggregation service reads
 * and writes; this class only decides which events belong together and in
 * which order, which is the part worth testing on its own.
 *
 * TWO ORDERINGS, AND THE REASON THERE ARE TWO. A client that keeps a
 * session id (persistClientId on, consent given) numbers its events
 * monotonically across page loads, so within that session the sequence is
 * the truth and the client clock only breaks ties. A cookieless client
 * starts its counter at zero on EVERY page load, because it stores
 * nothing between them; sorting those by sequence first would interleave
 * every page's first event ahead of every page's second. There the
 * client clock (server-clamped at ingest) orders, and the sequence breaks
 * ties within one page load. Neither ordering consults the receipt time,
 * which is the one a slow connection scrambles.
 *
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
 */
class TrafficSessioniser {

	/**
	 * Group a day's events into sessions.
	 *
	 * @param array<int, array<string, mixed>> $events         The raw events, any order.
	 * @param int                              $timeoutMinutes Inactivity after which a new session starts.
	 *
	 * @return array<int, array<string, mixed>> The sessions, each `{visitor, explicit, events}`
	 *                                          with its events in journey order.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-end-by-inactivity-and-the-timeout-must-be-configured
	 */
	public function sessions(array $events, int $timeoutMinutes): array {
		$sessions = [];
		foreach ($this->groups(events: $events) as $group) {
			foreach ($this->groupSessions(group: $group, timeoutSeconds: $timeoutMinutes * 60) as $session) {
				$sessions[] = $session;
			}
		}

		return $sessions;
	}

	/**
	 * Gather the events by the session they belong to: the client-kept
	 * session id when there is one, else the visitor.
	 *
	 * @param array<int, array<string, mixed>> $events The raw events, any order.
	 *
	 * @return array<string, array<string, mixed>> The groups, each `{visitor, explicit, events}`,
	 *                                             in order of first appearance.
	 */
	private function groups(array $events): array {
		$groups = [];
		foreach ($events as $event) {
			$visitor = $this->visitor(event: $event);
			$sessionId = trim((string)($event['sessionId'] ?? ''));
			$key = $this->groupKey(visitor: $visitor, sessionId: $sessionId);
			if (isset($groups[$key]) === false) {
				$groups[$key] = ['visitor' => $visitor, 'explicit' => ($sessionId !== ''), 'events' => []];
			}

			$groups[$key]['events'][] = $this->stamped(event: $event);
		}

		return $groups;
	}

	/**
	 * The key a group hangs on: the session id when the client kept one,
	 * else the visitor.
	 *
	 * @param string $visitor   The visitor key.
	 * @param string $sessionId The trimmed client session id, '' when none.
	 *
	 * @return string The group key.
	 */
	private function groupKey(string $visitor, string $sessionId): string {
		if ($sessionId !== '') {
			return 's:' . $sessionId;
		}

		return 'v:' . $visitor;
	}

	/**
	 * The event with its ordering fields worked out: `_at` (client clock in
	 * seconds) and `_seq` (the client sequence number).
	 *
	 * @param array<string, mixed> $event The raw event.
	 *
	 * @return array<string, mixed> The event with `_at` and `_seq`.
	 */
	private function stamped(array $event): array {
		$event['_at'] = $this->seconds(value: ($event['occurredAt'] ?? null));
		$event['_seq'] = (int)($event['sequence'] ?? 0);
		return $event;
	}

	/**
	 * Order one group's events, drop repeated sequence numbers of a
	 * client-kept session and cut the journey on inactivity.
	 *
	 * @param array<string, mixed> $group          The group, `{visitor, explicit, events}`.
	 * @param int                  $timeoutSeconds Inactivity after which a new session starts.
	 *
	 * @return array<int, array<string, mixed>> The group's sessions.
	 */
	private function groupSessions(array $group, int $timeoutSeconds): array {
		usort($group['events'], $this->comparator(explicit: $group['explicit']));
		$repeats = 0;
		if ($group['explicit'] === true) {
			$kept = $this->withoutRepeats(events: $group['events']);
			$repeats = (count($group['events']) - count($kept));
			$group['events'] = $kept;
		}

		$sessions = [];
		foreach ($this->split(events: $group['events'], timeoutSeconds: $timeoutSeconds) as $run) {
			$sessions[] = ['visitor' => $group['visitor'], 'explicit' => $group['explicit'], 'events' => $run, 'repeats' => $repeats];
			// Counted once per group, not once per cut run.
			$repeats = 0;
		}

		return $sessions;
	}

	/**
	 * Drop the events of a client-kept session whose sequence number was
	 * already used: the first one in journey order stays. A client that
	 * resets its counter, or a beacon delivered twice, must not add a second
	 * step to a journey or count a page twice (task 1.4).
	 *
	 * @param array<int, array<string, mixed>> $events Events ordered by sequence, then clock.
	 *
	 * @return array<int, array<string, mixed>> The events with distinct sequences.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
	 */
	private function withoutRepeats(array $events): array {
		$seen = [];
		$kept = [];
		foreach ($events as $event) {
			if (isset($seen[$event['_seq']]) === true) {
				continue;
			}

			$seen[$event['_seq']] = true;
			$kept[] = $event;
		}

		return $kept;
	}

	/**
	 * The identity a session hangs on: the persisted client id when the
	 * portal allowed one, else the daily visitor hash.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The visitor key.
	 */
	private function visitor(array $event): string {
		$clientId = trim((string)($event['clientId'] ?? ''));
		if ($clientId !== '') {
			return 'c:' . $clientId;
		}

		return 'h:' . (string)($event['visitorHash'] ?? '');
	}

	/**
	 * The sort order for one group's events (see the class docblock).
	 *
	 * @param bool $explicit Whether the group carries a client session id.
	 *
	 * @return callable(array, array): int The comparator.
	 */
	private function comparator(bool $explicit): callable {
		if ($explicit === true) {
			return static fn (array $a, array $b): int => [$a['_seq'], $a['_at']] <=> [$b['_seq'], $b['_at']];
		}

		return static fn (array $a, array $b): int => [$a['_at'], $a['_seq']] <=> [$b['_at'], $b['_seq']];
	}

	/**
	 * Cut an ordered run of events wherever the gap exceeds the timeout.
	 *
	 * The previous session closes at ITS last event, not at the event that
	 * opened the next one: a visitor who returns after an hour did not
	 * spend that hour on the site.
	 *
	 * @param array<int, array<string, mixed>> $events         Ordered events.
	 * @param int                              $timeoutSeconds The inactivity window.
	 *
	 * @return array<int, array<int, array<string, mixed>>> Runs of events.
	 */
	private function split(array $events, int $timeoutSeconds): array {
		$runs = [];
		$current = [];
		$last = null;
		foreach ($events as $event) {
			if ($last !== null && ($event['_at'] - $last) > $timeoutSeconds) {
				$runs[] = $current;
				$current = [];
			}

			$current[] = $event;
			$last = $event['_at'];
		}

		if ($current !== []) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * An ISO 8601 instant as seconds since the epoch, with fractions.
	 *
	 * @param mixed $value The stored instant.
	 *
	 * @return float Seconds; 0 when unparseable.
	 */
	private function seconds(mixed $value): float {
		if (is_string($value) === false || $value === '') {
			return 0.0;
		}

		$stamp = strtotime($value);
		if ($stamp === false) {
			return 0.0;
		}

		// The seconds come from strtotime(), which truncates; the
		// milliseconds the collector stored are added back so two events
		// in one second still order.
		$fraction = 0.0;
		if (preg_match('/\.(\d{1,3})(?:Z|[+-]\d{2}:?\d{2})?$/', $value, $match) === 1) {
			$fraction = ((float)str_pad($match[1], 3, '0')) / 1000;
		}

		return $stamp + $fraction;
	}
}
