<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use DateTimeImmutable;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use PHPUnit\Framework\TestCase;

/**
 * operate-availability-report REQ-OAR-004: twelve full months, each as a
 * percentage to two decimals, the outages of the period, and the same as CSV.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
class AvailabilityReportTest extends TestCase {
	public function testMonthlyPercentage(): void {
		$store = $this->getMockBuilder(AvailabilityStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['dailyBetween', 'outagesBetween'])
			->getMock();
		$store->expects($this->once())->method('dailyBetween')
			->with('open-tilburg', '2025-09-01', '2026-08-31')
			->willReturn([
				'2026-08-01' => ['intervals' => 288, 'available' => 276, 'degraded' => 0, 'down' => 12, 'noCheck' => 12],
				'2026-08-02' => ['intervals' => 288, 'available' => 288, 'degraded' => 0, 'down' => 0, 'noCheck' => 0],
				'2025-09-15' => ['intervals' => 288, 'available' => 287, 'degraded' => 1, 'down' => 0, 'noCheck' => 0],
			]);
		$store->method('outagesBetween')->willReturn([
			['uuid' => 'o-1', 'startedAt' => '2026-08-01T03:00:00+00:00', 'endedAt' => '2026-08-01T04:00:00+00:00', 'durationMinutes' => 60, 'cause' => 'no-check'],
		]);

		$report = (new AvailabilityReport($store))->forPortal(portal: 'open-tilburg', now: new DateTimeImmutable('2026-09-29T10:00:00+00:00'));

		$this->assertCount(12, $report['months']);
		$this->assertSame('2025-09', $report['months'][0]['month']);
		$this->assertSame('2026-08', $report['months'][11]['month']);
		$this->assertSame(99.65, $report['months'][0]['percentage']);
		$this->assertSame(97.92, $report['months'][11]['percentage']);
		// A month with nothing measured says so rather than reading as 0 or 100.
		$this->assertNull($report['months'][5]['percentage']);
		$this->assertSame([['startedAt' => '2026-08-01T03:00:00+00:00', 'endedAt' => '2026-08-01T04:00:00+00:00', 'durationMinutes' => 60, 'cause' => 'no-check']], $report['outages']);

		$csv = (new AvailabilityReport($store))->csv(report: $report);
		$this->assertStringStartsWith("month,availability_percent,intervals,available,degraded,down,no_check\n2025-09,99.65,288,287,1,0,0\n", $csv);
		$this->assertStringContainsString("2026-02,,0,0,0,0,0\n", $csv);
		$this->assertStringContainsString("outage_started,outage_ended,duration_minutes,cause\n2026-08-01T03:00:00+00:00,2026-08-01T04:00:00+00:00,60,no-check\n", $csv);
	}//end testMonthlyPercentage()
}//end class
