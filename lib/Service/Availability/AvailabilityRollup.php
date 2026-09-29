<?php

/**
 * Portaliq availability roll-up
 *
 * Folds one availability check, and the intervals missed since the previous
 * one, into a portal's daily records and its outage.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Availability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The arithmetic of availability, with no storage of its own.
 *
 * A gap is downtime: every five-minute interval between the previous check
 * and this one in which nothing was checked counts as down with cause
 * `no-check`. A run of intervals that were not available is one outage; it
 * keeps the cause of its first interval and is closed by the first check that
 * finds the portal available.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
 */
class AvailabilityRollup {
	/**
	 * The check interval, in seconds.
	 */
	public const INTERVAL = 300;

	public const AVAILABLE = 'available';

	public const DEGRADED = 'degraded';

	public const DOWN = 'down';

	/**
	 * Missed intervals further back than this are not counted: the records
	 * they would land on are past the thirteen months that are kept.
	 */
	private const MAX_GAP_DAYS = 396;

	/**
	 * Fold one check into the portal's days and outage.
	 *
	 * @param string $portal The portal slug.
	 * @param DateTimeImmutable $checkedAt When the check ran.
	 * @param string $status AVAILABLE, DEGRADED or DOWN.
	 * @param string $cause Why it was not available ('' when it was).
	 * @param array<string, array<string, mixed>> $days The stored daily
	 *                                                  records by date that
	 *                                                  the check and the gap
	 *                                                  before it touch.
	 * @param array<string, mixed>|null $openOutage The portal's open outage.
	 *
	 * @return array{days: array<string, array<string, mixed>>, open: array<string, mixed>|null, closed: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
	 */
	public function record(string $portal, DateTimeImmutable $checkedAt, string $status, string $cause, array $days, ?array $openOutage): array {
		$checkedAt = $checkedAt->setTimezone(new DateTimeZone('UTC'));
		$state = ['days' => $days, 'open' => $openOutage, 'closed' => []];

		foreach ($this->missedIntervals(checkedAt: $checkedAt, last: $this->lastCheckAt(days: $days)) as $start) {
			$state = $this->fold(state: $state, portal: $portal, start: $start, status: self::DOWN, cause: 'no-check');
		}

		$state = $this->fold(state: $state, portal: $portal, start: $checkedAt, status: $status, cause: $cause);
		$state['days'][$checkedAt->format('Y-m-d')]['lastCheckAt'] = $checkedAt->format(DATE_ATOM);

		if ($state['open'] !== null) {
			$state['open']['durationMinutes'] = $this->minutesBetween(from: (string)$state['open']['startedAt'], to: $checkedAt);
		}

		return $state;
	}//end record()

	/**
	 * The latest `lastCheckAt` among the given days, or null.
	 *
	 * @param array<string, array<string, mixed>> $days The daily records.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
	 */
	public function lastCheckAt(array $days): ?DateTimeImmutable {
		$latest = null;
		foreach ($days as $day) {
			$value = (string)($day['lastCheckAt'] ?? '');
			if ($value === '') {
				continue;
			}

			try {
				$candidate = new DateTimeImmutable($value);
			} catch (Throwable) {
				continue;
			}

			if ($latest === null || $candidate > $latest) {
				$latest = $candidate;
			}
		}

		return $latest;
	}//end lastCheckAt()

	/**
	 * The start of every interval between the previous check and this one in
	 * which no check ran.
	 *
	 * @param DateTimeImmutable $checkedAt This check.
	 * @param DateTimeImmutable|null $last The previous check, or null for the first.
	 *
	 * @return array<int, DateTimeImmutable>
	 */
	private function missedIntervals(DateTimeImmutable $checkedAt, ?DateTimeImmutable $last): array {
		if ($last === null) {
			return [];
		}

		$missed = (intdiv($checkedAt->getTimestamp() - $last->getTimestamp(), self::INTERVAL) - 1);
		$floor = ($checkedAt->getTimestamp() - (self::MAX_GAP_DAYS * 86400));

		$starts = [];
		for ($index = 1; $index <= $missed; $index++) {
			$start = ($last->getTimestamp() + ($index * self::INTERVAL));
			if ($start >= $floor) {
				$starts[] = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('UTC'));
			}
		}

		return $starts;
	}//end missedIntervals()

	/**
	 * Count one interval on its day and move the outage along.
	 *
	 * @param array<string, mixed> $state The state so far: `days`, `open`, `closed`.
	 * @param string $portal The portal slug.
	 * @param DateTimeImmutable $start The interval's start.
	 * @param string $status AVAILABLE, DEGRADED or DOWN.
	 * @param string $cause Why it was not available.
	 *
	 * @return array{days: array<string, array<string, mixed>>, open: array<string, mixed>|null, closed: array<int, array<string, mixed>>}
	 */
	private function fold(array $state, string $portal, DateTimeImmutable $start, string $status, string $cause): array {
		$date = $start->format('Y-m-d');
		$day = ($state['days'][$date] ?? ['portal' => $portal, 'date' => $date]);
		foreach (['intervals', self::AVAILABLE, self::DEGRADED, self::DOWN, 'noCheck'] as $counter) {
			$day[$counter] = (int)($day[$counter] ?? 0);
		}

		$day['intervals']++;
		$day[$status]++;
		if ($cause === 'no-check') {
			$day['noCheck']++;
		}

		$state['days'][$date] = $day;

		if ($status === self::AVAILABLE) {
			if ($state['open'] !== null) {
				$closed = $state['open'];
				$closed['endedAt'] = $start->format(DATE_ATOM);
				$closed['durationMinutes'] = $this->minutesBetween(from: (string)$closed['startedAt'], to: $start);
				$state['closed'][] = $closed;
				$state['open'] = null;
			}

			return $state;
		}

		if ($state['open'] === null) {
			$state['open'] = ['portal' => $portal, 'startedAt' => $start->format(DATE_ATOM), 'cause' => $cause, 'durationMinutes' => 0];
		}

		return $state;
	}//end fold()

	/**
	 * Whole minutes from an ISO timestamp to a moment.
	 *
	 * @param string $from The start, ISO 8601.
	 * @param DateTimeImmutable $to The end.
	 *
	 * @return int
	 */
	private function minutesBetween(string $from, DateTimeImmutable $to): int {
		try {
			$start = new DateTimeImmutable($from);
		} catch (Throwable) {
			return 0;
		}

		return max(0, intdiv($to->getTimestamp() - $start->getTimestamp(), 60));
	}//end minutesBetween()
}//end class
