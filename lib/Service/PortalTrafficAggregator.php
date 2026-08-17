<?php

/**
 * Portaliq Portal Traffic Aggregator
 *
 * Turns stored events into the numbers a portal's operator reads.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reconstructs journeys and aggregates them.
 *
 * PURE, AND DELIBERATELY SO. Every method here takes rows and returns numbers;
 * nothing reads a clock, a database or a request. That is what makes the two
 * properties this has to have testable at all: the same input must always
 * produce the same output (4.5, idempotence), and events delivered out of
 * order must produce the same journey as events delivered in order (4.2, the
 * case that only appears on a slow connection).
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficAggregator {

	/**
	 * A session with at least this many page views is "engaged".
	 *
	 * GA4 calls a session engaged at 10 seconds, one conversion, or two page
	 * views. Only the last of those is answerable from what this collector
	 * stores — there is no dwell timer and no conversion — so that is the one
	 * used, and it is named rather than dressed up as the same metric.
	 */
	private const ENGAGED_MIN_VIEWS = 2;


	/**
	 * Group events into journeys.
	 *
	 * ORDERED BY `sequence`, NEVER BY ARRIVAL. A beacon batch can be delayed,
	 * retried, or overtaken by a later one — routinely, on a slow connection —
	 * and ordering by receipt would then invent journeys nobody made: an exit
	 * page that was really the entrance, a transition that ran backwards.
	 * `sequence` is the client's own count and is the only ordering that
	 * survives the network.
	 *
	 * A SESSION IS ALSO SPLIT ON TIME, because the sessionId is chosen by the
	 * client. A browser left open across a lunch break, a clock that jumped, a
	 * client that reused an id — each produces one session id spanning a gap no
	 * visit spans. Splitting on the portal's own idle window makes the server's
	 * answer independent of the client's bookkeeping.
	 *
	 * @param array<int, array<string, mixed>> $events         The stored events.
	 * @param int                              $timeoutMinutes The portal's idle window.
	 *
	 * @return array<int, array<int, array<string, mixed>>> One list per journey, in order.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function journeys(array $events, int $timeoutMinutes = 30): array {
		$bySession = [];
		foreach ($events as $event) {
			$sessionId = (string)($event['sessionId'] ?? '');
			if ($sessionId === '') {
				continue;
			}

			$bySession[$sessionId][] = $event;
		}

		// Sorted so the OUTPUT does not depend on the order rows came back
		// from storage — the same property idempotence needs.
		ksort($bySession);

		$timeout = (max(1, $timeoutMinutes) * 60);
		$journeys = [];
		foreach ($bySession as $rows) {
			usort(
				$rows,
				static fn (array $a, array $b): int => ((int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0))
			);

			foreach ($this->splitOnIdle(rows: $rows, timeout: $timeout) as $journey) {
				$journeys[] = $journey;
			}
		}

		return $journeys;
	}//end journeys()


	/**
	 * Split one session's ordered rows wherever the idle window is exceeded.
	 *
	 * @param array<int, array<string, mixed>> $rows    The session's rows, already ordered.
	 * @param int                              $timeout The idle window, in seconds.
	 *
	 * @return array<int, array<int, array<string, mixed>>> One or more journeys.
	 */
	private function splitOnIdle(array $rows, int $timeout): array {
		$journeys = [];
		$current = [];
		$previous = null;

		foreach ($rows as $row) {
			$seenAt = $this->timeOf(event: $row);
			$lapsed = $previous !== null && $seenAt !== null && ($seenAt - $previous) > $timeout;

			if ($lapsed === true && $current !== []) {
				$journeys[] = $current;
				$current = [];
			}

			$current[] = $row;
			if ($seenAt !== null) {
				$previous = $seenAt;
			}
		}

		if ($current !== []) {
			$journeys[] = $current;
		}

		return $journeys;
	}//end splitOnIdle()


	/**
	 * When an event happened, as an epoch second.
	 *
	 * `receivedAt` is preferred over the client's `timestamp` — the client's
	 * clock is whatever the visitor's device says, and a device set a year
	 * wrong would otherwise split every one of its visits into single-page
	 * journeys. The client's own value is the fallback, and null means neither
	 * could be read, which is treated as "no gap" rather than as a gap.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return int|null The epoch second, or null.
	 */
	private function timeOf(array $event): ?int {
		foreach (['receivedAt', 'timestamp'] as $field) {
			$value = trim((string)($event[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			$parsed = strtotime($value);
			if ($parsed !== false) {
				return $parsed;
			}
		}

		return null;
	}//end timeOf()


	/**
	 * Aggregate a portal's events into the figures its operator reads.
	 *
	 * @param array<int, array<string, mixed>> $events         The stored events.
	 * @param int                              $timeoutMinutes The portal's idle window.
	 *
	 * @return array<string, mixed> The aggregate.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function aggregate(array $events, int $timeoutMinutes = 30): array {
		$journeys = $this->journeys(events: $events, timeoutMinutes: $timeoutMinutes);

		$views = [];
		$entrances = [];
		$exits = [];
		$transitions = [];
		$engaged = 0;
		$visitors = [];

		foreach ($journeys as $journey) {
			$pages = $this->pagesOf(journey: $journey);
			foreach ($journey as $event) {
				$visitors[(string)($event['clientId'] ?? '')] = true;
			}

			if ($pages === []) {
				continue;
			}

			foreach ($pages as $page) {
				$views[$page] = (($views[$page] ?? 0) + 1);
			}

			$entrances[$pages[0]] = (($entrances[$pages[0]] ?? 0) + 1);
			$last = $pages[(count($pages) - 1)];
			$exits[$last] = (($exits[$last] ?? 0) + 1);

			$steps = count($pages);
			for ($i = 1; $i < $steps; $i++) {
				$key = ($pages[($i - 1)] . ' → ' . $pages[$i]);
				$transitions[$key] = (($transitions[$key] ?? 0) + 1);
			}

			if (count($pages) >= self::ENGAGED_MIN_VIEWS) {
				$engaged++;
			}
		}

		unset($visitors['']);

		return [
			'sessions' => count($journeys),
			'engagedSessions' => $engaged,
			'visitors' => count($visitors),
			'pageViews' => array_sum($views),
			'views' => $this->ranked(counts: $views),
			'entrances' => $this->ranked(counts: $entrances),
			'exits' => $this->ranked(counts: $exits),
			'transitions' => $this->ranked(counts: $transitions),
		];
	}//end aggregate()


	/**
	 * The pages a journey visited, in order.
	 *
	 * Only `page_view` counts as a page. A `search` or an `outbound_click`
	 * happens ON a page and would otherwise inflate both the view count and
	 * the transition list with steps the visitor never took.
	 *
	 * @param array<int, array<string, mixed>> $journey The ordered journey.
	 *
	 * @return array<int, string> The page paths.
	 */
	private function pagesOf(array $journey): array {
		$pages = [];
		foreach ($journey as $event) {
			if ((string)($event['name'] ?? '') !== 'page_view') {
				continue;
			}

			$page = trim((string)($event['pageLocation'] ?? ''));
			if ($page !== '') {
				$pages[] = $page;
			}
		}

		return $pages;
	}//end pagesOf()


	/**
	 * A count map as a ranked list.
	 *
	 * TIES BREAK ON THE KEY, not on insertion order. Without it two runs over
	 * the same data can rank equal pages differently, and a table that
	 * reshuffles between refreshes reads as changing traffic.
	 *
	 * @param array<string, int> $counts The counts.
	 *
	 * @return array<int, array{key: string, count: int}> The ranked list.
	 */
	private function ranked(array $counts): array {
		$keys = array_keys($counts);
		usort(
			$keys,
			static function (string $keyA, string $keyB) use ($counts): int {
				$byCount = ($counts[$keyB] <=> $counts[$keyA]);
				if ($byCount !== 0) {
					return $byCount;
				}

				return strcmp($keyA, $keyB);
			}
		);

		$ranked = [];
		foreach ($keys as $key) {
			$ranked[] = ['key' => $key, 'count' => $counts[$key]];
		}

		return $ranked;
	}//end ranked()


	/**
	 * Aggregate per UTC day.
	 *
	 * A JOURNEY IS ASSIGNED TO THE DAY IT STARTED, whole. Splitting one at
	 * midnight would turn a single late-evening visit into two sessions with a
	 * fabricated entrance and a fabricated exit either side of the boundary —
	 * and on a portal people read in the evening that is not a rounding error,
	 * it is a nightly spike in the session count that nothing caused.
	 *
	 * UTC rather than local time, because the alternative is a figure that
	 * shifts twice a year and a day that is 23 or 25 hours long.
	 *
	 * @param array<int, array<string, mixed>> $events         The stored events.
	 * @param int                              $timeoutMinutes The portal's idle window.
	 *
	 * @return array<string, array<string, mixed>> Aggregate by `YYYY-MM-DD`, oldest first.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function aggregateByDay(array $events, int $timeoutMinutes = 30): array {
		$byDay = [];
		foreach ($this->journeys(events: $events, timeoutMinutes: $timeoutMinutes) as $journey) {
			$startedAt = $this->timeOf(event: $journey[0]);
			if ($startedAt === null) {
				continue;
			}

			$byDay[gmdate('Y-m-d', $startedAt)][] = $journey;
		}

		ksort($byDay);

		$aggregates = [];
		foreach ($byDay as $day => $journeys) {
			// The journeys are already grouped and ordered; flattening them back
			// into events lets `aggregate()` stay the single implementation of
			// what the figures MEAN. Two implementations would drift, and the
			// daily one is the half nobody would check.
			$flat = [];
			foreach ($journeys as $journey) {
				foreach ($journey as $event) {
					$flat[] = $event;
				}
			}

			$aggregates[$day] = $this->aggregate(events: $flat, timeoutMinutes: $timeoutMinutes);
		}

		return $aggregates;
	}//end aggregateByDay()


	/**
	 * Which stored events are past a portal's retention window.
	 *
	 * RETURNS THE IDS RATHER THAN DELETING THEM. Deciding what is expired and
	 * carrying out the deletion are different responsibilities with very
	 * different blast radii, and keeping the decision pure means it can be
	 * tested exhaustively without anything being deleted to find out.
	 *
	 * A row whose age cannot be read is KEPT. Deleting what you cannot date is
	 * how a retention job becomes a data-loss incident.
	 *
	 * @param array<int, array<string, mixed>> $events        The stored events.
	 * @param int                              $retentionDays The portal's window.
	 * @param int                              $now           The current epoch second.
	 *
	 * @return array<int, string> The ids to delete.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function expiredIds(array $events, int $retentionDays, int $now): array {
		if ($retentionDays <= 0) {
			return [];
		}

		$cutoff = ($now - ($retentionDays * 86400));
		$expired = [];
		foreach ($events as $event) {
			$seenAt = $this->timeOf(event: $event);
			$id = trim((string)($event['id'] ?? ''));
			if ($seenAt === null || $id === '') {
				continue;
			}

			if ($seenAt < $cutoff) {
				$expired[] = $id;
			}
		}

		return $expired;
	}//end expiredIds()


}//end class
