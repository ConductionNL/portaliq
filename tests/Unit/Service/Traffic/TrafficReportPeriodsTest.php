<?php

/**
 * TrafficReportPeriods: the period a report or an alert covers, and the
 * period it compares against, across week, month and year boundaries.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Traffic
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Traffic\TrafficReportPeriods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Report and alert periods.
 */
class TrafficReportPeriodsTest extends TestCase {
	/**
	 * A period from its five days, in the order the service returns them.
	 *
	 * @param array<int, string> $days The key, from, to, previousFrom and previousTo.
	 *
	 * @return array<string, string>
	 */
	private function period(array $days): array {
		return array_combine(keys: ['key', 'from', 'to', 'previousFrom', 'previousTo'], values: $days);
	}//end period()

	/**
	 * Report cadences, moments and the periods they cover.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
	 */
	public static function reportPeriods(): array {
		return [
			'daily is yesterday'               => ['daily', '2026-11-14 15:00:00', ['2026-11-13', '2026-11-13', '2026-11-13', '2026-11-12', '2026-11-12']],
			'daily on new year'                => ['daily', '2026-01-01 00:00:00', ['2025-12-31', '2025-12-31', '2025-12-31', '2025-12-30', '2025-12-30']],
			'unknown cadence is daily'         => ['hourly', '2026-03-01 08:00:00', ['2026-02-28', '2026-02-28', '2026-02-28', '2026-02-27', '2026-02-27']],
			'weekly is last iso week'          => ['weekly', '2026-11-14 12:00:00', ['2026-W45', '2026-11-02', '2026-11-08', '2026-10-26', '2026-11-01']],
			'weekly on a monday'               => ['weekly', '2026-11-09 00:00:00', ['2026-W45', '2026-11-02', '2026-11-08', '2026-10-26', '2026-11-01']],
			'weekly on a sunday'               => ['weekly', '2026-11-08 23:59:59', ['2026-W44', '2026-10-26', '2026-11-01', '2026-10-19', '2026-10-25']],
			'weekly across the year'           => ['weekly', '2026-01-07 09:00:00', ['2026-W01', '2025-12-29', '2026-01-04', '2025-12-22', '2025-12-28']],
			'monthly is last calendar month'   => ['monthly', '2026-11-14 12:00:00', ['2026-10', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30']],
			'monthly after a leap february'    => ['monthly', '2028-03-31 12:00:00', ['2028-02', '2028-02-01', '2028-02-29', '2028-01-01', '2028-01-31']],
			'monthly in january'               => ['monthly', '2027-01-15 12:00:00', ['2026-12', '2026-12-01', '2026-12-31', '2026-11-01', '2026-11-30']],
			'monthly compared to short month'  => ['monthly', '2026-03-31 12:00:00', ['2026-02', '2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31']],
		];
	}//end reportPeriods()

	/**
	 * Each cadence covers the last whole day, ISO week or calendar month, compared to the one before.
	 *
	 * @param string $cadence The report cadence.
	 * @param string $now The moment, in UTC.
	 * @param array<int, string> $expected The period's days.
	 *
	 * @return void
	 */
	#[DataProvider('reportPeriods')]
	public function testTheReportPeriodIsTheLastWholeOne(string $cadence, string $now, array $expected): void {
		$moment = new DateTimeImmutable($now, new DateTimeZone('UTC'));
		$this->assertSame(expected: $this->period(days: $expected), actual: (new TrafficReportPeriods())->reportPeriod(cadence: $cadence, now: $moment));
	}//end testTheReportPeriodIsTheLastWholeOne()

	/**
	 * The day is taken in UTC, whatever zone the moment carries.
	 *
	 * @return void
	 */
	public function testTheDayIsTakenInUtc(): void {
		$amsterdam = new DateTimeImmutable('2026-11-14 00:30:00', new DateTimeZone('Europe/Amsterdam'));
		$period    = (new TrafficReportPeriods())->reportPeriod(cadence: 'daily', now: $amsterdam);
		$this->assertSame(expected: '2026-11-12', actual: $period['key']);
	}//end testTheDayIsTakenInUtc()

	/**
	 * Alerts and the periods they look at.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
	 */
	public static function alertPeriods(): array {
		return [
			'below, a day, is yesterday'       => ['day', 'below', ['2026-11-12', '2026-11-12', '2026-11-12', '2026-11-11', '2026-11-11']],
			'below, a week, is last week'      => ['week', 'below', ['2026-W45', '2026-11-02', '2026-11-08', '2026-10-26', '2026-11-01']],
			'above, a day, is today so far'    => ['day', 'above', ['2026-11-13', '2026-11-13', '2026-11-13', '2026-11-12', '2026-11-12']],
			'above, a week, is this week'      => ['week', 'above', ['2026-W46', '2026-11-09', '2026-11-13', '2026-11-04', '2026-11-08']],
			'unknown comparison reads as below' => ['week', 'equal', ['2026-W45', '2026-11-02', '2026-11-08', '2026-10-26', '2026-11-01']],
		];
	}//end alertPeriods()

	/**
	 * A rise is caught while it happens; a drop only over a whole period.
	 *
	 * @param string $period The alert period.
	 * @param string $comparison The alert comparison.
	 * @param array<int, string> $expected The period's days.
	 *
	 * @return void
	 */
	#[DataProvider('alertPeriods')]
	public function testTheAlertPeriodDependsOnTheComparison(string $period, string $comparison, array $expected): void {
		$friday = new DateTimeImmutable('2026-11-13 18:00:00', new DateTimeZone('UTC'));
		$this->assertSame(
			expected: $this->period(days: $expected),
			actual: (new TrafficReportPeriods())->alertPeriod(period: $period, comparison: $comparison, now: $friday)
		);
	}//end testTheAlertPeriodDependsOnTheComparison()

	/**
	 * On a Monday a rising week is that one day, compared to the Sunday before.
	 *
	 * @return void
	 */
	public function testARisingWeekOnAMondayIsOneDay(): void {
		$monday = new DateTimeImmutable('2026-11-09 07:00:00', new DateTimeZone('UTC'));
		$this->assertSame(
			expected: $this->period(days: ['2026-W46', '2026-11-09', '2026-11-09', '2026-11-08', '2026-11-08']),
			actual: (new TrafficReportPeriods())->alertPeriod(period: 'week', comparison: 'above', now: $monday)
		);
	}//end testARisingWeekOnAMondayIsOneDay()

	/**
	 * The ISO week key uses the ISO year, so the last days of December can be week 1.
	 *
	 * @return void
	 */
	public function testTheWeekKeyUsesTheIsoYear(): void {
		$tuesday = new DateTimeImmutable('2024-12-31 10:00:00', new DateTimeZone('UTC'));
		$period  = (new TrafficReportPeriods())->alertPeriod(period: 'week', comparison: 'above', now: $tuesday);
		$this->assertSame(expected: '2025-W01', actual: $period['key']);
		$this->assertSame(expected: '2024-12-30', actual: $period['from']);
	}//end testTheWeekKeyUsesTheIsoYear()
}//end class
