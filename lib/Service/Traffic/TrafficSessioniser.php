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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
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
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-end-by-inactivity-and-the-timeout-must-be-configured
	 */
	public function sessions(array $events, int $timeoutMinutes): array {
		$groups = [];
		foreach ($events as $event) {
			$visitor = $this->visitor(event: $event);
			$sessionId = trim((string)($event['sessionId'] ?? ''));
			$explicit = ($sessionId !== '');
			$key = 'v:' . $visitor;
			if ($explicit === true) {
				$key = 's:' . $sessionId;
			}

			if (isset($groups[$key]) === false) {
				$groups[$key] = ['visitor' => $visitor, 'explicit' => $explicit, 'events' => []];
			}

			$event['_at'] = $this->seconds(value: ($event['occurredAt'] ?? null));
			$event['_seq'] = (int)($event['sequence'] ?? 0);
			$groups[$key]['events'][] = $event;
		}

		$sessions = [];
		foreach ($groups as $group) {
			usort($group['events'], $this->comparator(explicit: $group['explicit']));
			foreach ($this->split(events: $group['events'], timeoutSeconds: $timeoutMinutes * 60) as $run) {
				$sessions[] = ['visitor' => $group['visitor'], 'explicit' => $group['explicit'], 'events' => $run];
			}
		}

		return $sessions;
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
