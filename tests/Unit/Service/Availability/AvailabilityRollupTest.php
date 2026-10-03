<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use DateTimeImmutable;
use OCA\Portaliq\Service\Availability\AvailabilityRollup;
use PHPUnit\Framework\TestCase;

/**
 * operate-availability-report REQ-OAR-001 and REQ-OAR-002: every check adds
 * one interval to its day, an interval without a check counts as down with
 * cause no-check, and a run of intervals that were not available is one
 * outage, closed by the first check that finds the portal available.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
 */
class AvailabilityRollupTest extends TestCase {
	public function testMissingHourIsTwelveDownIntervals(): void {
		$rollup = new AvailabilityRollup();
		$day = ['uuid' => 'd-1', 'portal' => 'open-tilburg', 'date' => '2026-09-29', 'intervals' => 35, 'available' => 35, 'degraded' => 0, 'down' => 0, 'noCheck' => 0, 'lastCheckAt' => '2026-09-29T02:55:00+00:00'];

		$result = $rollup->record(
			portal: 'open-tilburg',
			checkedAt: new DateTimeImmutable('2026-09-29T04:00:00+00:00'),
			status: AvailabilityRollup::AVAILABLE,
			cause: '',
			days: ['2026-09-29' => $day],
			openOutage: null
		);

		$today = $result['days']['2026-09-29'];
		$this->assertSame('d-1', $today['uuid']);
		$this->assertSame(35 + 12 + 1, $today['intervals']);
		$this->assertSame(12, $today['down']);
		$this->assertSame(12, $today['noCheck']);
		$this->assertSame(36, $today['available']);
		$this->assertSame('2026-09-29T04:00:00+00:00', $today['lastCheckAt']);

		$this->assertNull($result['open']);
		$this->assertCount(1, $result['closed']);
		$this->assertSame('no-check', $result['closed'][0]['cause']);
		$this->assertSame('2026-09-29T03:00:00+00:00', $result['closed'][0]['startedAt']);
		$this->assertSame('2026-09-29T04:00:00+00:00', $result['closed'][0]['endedAt']);
		$this->assertSame(60, $result['closed'][0]['durationMinutes']);
	}//end testMissingHourIsTwelveDownIntervals()

	/**
	 * @spec openspec/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function testAFailingCheckOpensAnOutageAndTheNextOneExtendsIt(): void {
		$rollup = new AvailabilityRollup();

		$first = $rollup->record(
			portal: 'open-tilburg',
			checkedAt: new DateTimeImmutable('2026-09-29T10:00:00+00:00'),
			status: AvailabilityRollup::DOWN,
			cause: 'site-error',
			days: [],
			openOutage: null
		);
		$this->assertSame(1, $first['days']['2026-09-29']['down']);
		$this->assertSame(0, $first['days']['2026-09-29']['noCheck']);
		$this->assertSame(['portal' => 'open-tilburg', 'startedAt' => '2026-09-29T10:00:00+00:00', 'cause' => 'site-error', 'durationMinutes' => 0], $first['open']);

		$second = $rollup->record(
			portal: 'open-tilburg',
			checkedAt: new DateTimeImmutable('2026-09-29T10:05:00+00:00'),
			status: AvailabilityRollup::DEGRADED,
			cause: 'health-degraded',
			days: $first['days'],
			openOutage: $first['open'] + ['uuid' => 'o-1']
		);
		$this->assertSame(1, $second['days']['2026-09-29']['degraded']);
		$this->assertSame('o-1', $second['open']['uuid']);
		// The outage keeps the cause of its first interval.
		$this->assertSame('site-error', $second['open']['cause']);
		$this->assertSame(5, $second['open']['durationMinutes']);
		$this->assertSame([], $second['closed']);
	}//end testAFailingCheckOpensAnOutageAndTheNextOneExtendsIt()

	public function testAGapAcrossMidnightCountsOnEachDay(): void {
		$rollup = new AvailabilityRollup();

		$result = $rollup->record(
			portal: 'open-tilburg',
			checkedAt: new DateTimeImmutable('2026-09-30T00:10:00+00:00'),
			status: AvailabilityRollup::AVAILABLE,
			cause: '',
			days: ['2026-09-29' => ['portal' => 'open-tilburg', 'date' => '2026-09-29', 'intervals' => 1, 'available' => 1, 'degraded' => 0, 'down' => 0, 'noCheck' => 0, 'lastCheckAt' => '2026-09-29T23:45:00+00:00']],
			openOutage: null
		);

		// 23:50 and 23:55 on the 29th, 00:00 and 00:05 on the 30th.
		$this->assertSame(2, $result['days']['2026-09-29']['noCheck']);
		$this->assertSame(2, $result['days']['2026-09-30']['noCheck']);
		$this->assertSame(3, $result['days']['2026-09-30']['intervals']);
		$this->assertSame('2026-09-30T00:10:00+00:00', $result['days']['2026-09-30']['lastCheckAt']);
		$this->assertSame('2026-09-29T23:45:00+00:00', $result['days']['2026-09-29']['lastCheckAt']);
	}//end testAGapAcrossMidnightCountsOnEachDay()

	public function testTheLastCheckIsFoundOnTheLatestDay(): void {
		$rollup = new AvailabilityRollup();

		$this->assertSame(
			'2026-09-29T23:45:00+00:00',
			$rollup->lastCheckAt(days: [
				'2026-09-28' => ['lastCheckAt' => '2026-09-28T23:55:00+00:00'],
				'2026-09-29' => ['lastCheckAt' => '2026-09-29T23:45:00+00:00'],
			])?->format(DATE_ATOM)
		);
		$this->assertNull($rollup->lastCheckAt(days: []));
	}//end testTheLastCheckIsFoundOnTheLatestDay()
}//end class
