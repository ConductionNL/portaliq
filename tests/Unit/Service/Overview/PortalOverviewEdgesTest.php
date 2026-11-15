<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Overview;

use DateTimeImmutable;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Overview\PortalOverview;
use OCA\Portaliq\Service\PublishedPortals;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The overview at its edges: the page cap, the UTC day window, and portals
 * that report no checks or are not there at all.
 */
#[CoversClass(PortalOverview::class)]
class PortalOverviewEdgesTest extends TestCase {
	/**
	 * Of more pages than the overview names, only the most viewed TOP_PAGES
	 * return, across portals.
	 *
	 * @return void
	 */
	public function testTrafficNamesOnlyTheMostViewedPages(): void {
		$folds = [
			'a' => ['pageViews' => 30, 'pages' => [['path' => '/a1', 'views' => 1], ['path' => '/a2', 'views' => 8], ['path' => '/a3', 'views' => 3]]],
			'b' => ['pageViews' => 21, 'pages' => [['path' => '/b1', 'views' => 9], ['path' => '/b2', 'views' => 2], ['path' => '/b3', 'views' => 6]]],
		];
		$asked = [];

		$out = $this->overview(slugs: ['a', 'b'], folds: $folds, reports: [], asked: $asked)
			->traffic(days: 7, now: new DateTimeImmutable('2026-10-07T09:00:00Z'));

		$this->assertSame(4, PortalOverview::TOP_PAGES);
		$this->assertSame(['/b1', '/a2', '/b3', '/a3'], array_column($out['pages'], 'path'));
		$this->assertSame(['b', 'a', 'b', 'a'], array_column($out['pages'], 'portal'));
		$this->assertSame(51, $out['pageViews']);
		$this->assertSame(0, $out['sessions']);
		$this->assertNull($out['engagedShare']);
	}//end testTrafficNamesOnlyTheMostViewedPages()

	/**
	 * The day window is counted in UTC: just after local midnight east of UTC
	 * is still the previous UTC day, and one day is a window of that day alone.
	 *
	 * @return void
	 */
	public function testTrafficWindowIsTheUtcDay(): void {
		$asked = [];

		$out = $this->overview(slugs: ['a'], folds: ['a' => []], reports: [], asked: $asked)
			->traffic(days: 1, now: new DateTimeImmutable('2026-09-11T01:00:00+02:00'));

		$this->assertSame(['traffic a 2026-09-10 2026-09-10'], $asked);
		$this->assertSame(['2026-09-10', '2026-09-10', 1], [$out['from'], $out['to'], $out['days']]);
		$this->assertSame(['pageViews' => 0, 'sessions' => 0, 'visitors' => 0, 'engagedSessions' => 0], array_intersect_key($out, array_flip(['pageViews', 'sessions', 'visitors', 'engagedSessions'])));
	}//end testTrafficWindowIsTheUtcDay()

	/**
	 * A month whose portals ran no checks has no percentage — never a
	 * division by zero — while the month and per-portal rows still show.
	 *
	 * @return void
	 */
	public function testAvailabilityWithoutChecksHasNoPercentage(): void {
		$reports = [
			'a' => ['months' => [['month' => '2026-09', 'intervals' => 0, 'available' => 0, 'percentage' => null]], 'outages' => []],
			'b' => ['months' => [['month' => '2026-09', 'intervals' => 0, 'available' => 0]]],
		];
		$asked = [];

		$out = $this->overview(slugs: ['a', 'b'], folds: [], reports: $reports, asked: $asked)
			->availability(now: new DateTimeImmutable('2026-10-02T10:00:00Z'));

		$this->assertSame(
			[
				'month' => '2026-09',
				'percentage' => null,
				'outages' => 0,
				'outageMinutes' => 0,
				'lastOutage' => null,
				'portals' => [
					['portal' => 'a', 'title' => 'A', 'percentage' => null],
					['portal' => 'b', 'title' => 'B', 'percentage' => null],
				],
			],
			$out
		);
		$this->assertSame(['avail a 1', 'avail b 1'], $asked);
	}//end testAvailabilityWithoutChecksHasNoPercentage()

	/**
	 * Without published portals nothing is asked of the stores and both
	 * overviews read empty.
	 *
	 * @return void
	 */
	public function testNoPublishedPortalsReadsEmpty(): void {
		$asked = [];
		$overview = $this->overview(slugs: [], folds: [], reports: [], asked: $asked);

		$availability = $overview->availability(now: new DateTimeImmutable('2026-10-02T10:00:00Z'));
		$traffic      = $overview->traffic(days: 30, now: new DateTimeImmutable('2026-10-02T10:00:00Z'));

		$this->assertSame([], $asked);
		$this->assertSame(['month' => '', 'percentage' => null, 'outages' => 0, 'outageMinutes' => 0, 'lastOutage' => null, 'portals' => []], $availability);
		$this->assertSame('2026-09-03', $traffic['from']);
		$this->assertSame([0, null, []], [$traffic['pageViews'], $traffic['engagedShare'], $traffic['pages']]);
	}//end testNoPublishedPortalsReadsEmpty()

	/**
	 * The overview over the given portal slugs (titled by the upper-cased slug),
	 * with each portal's folded traffic and availability report as given.
	 *
	 * @param array<int, string> $slugs The published portals.
	 * @param array<string, array<string, mixed>> $folds Folded traffic per portal.
	 * @param array<string, array<string, mixed>> $reports Availability report per portal.
	 * @param array<int, string> $asked Every store and report call, appended.
	 *
	 * @return PortalOverview
	 */
	private function overview(array $slugs, array $folds, array $reports, array &$asked): PortalOverview {
		$portals = $this->createMock(originalClassName: PublishedPortals::class);
		$portals->method('allPublishedPortals')->willReturn(
			array_map(static fn (string $slug): array => ['slug' => $slug, 'title' => strtoupper($slug)], $slugs)
		);
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);
		$traffic->method('dailyBetween')->willReturnCallback(
			static function (string $portal, string $from, string $to) use (&$asked): array {
				$asked[] = 'traffic ' . $portal . ' ' . $from . ' ' . $to;
				return [['portal' => $portal]];
			}
		);
		$numbers = $this->createMock(originalClassName: TrafficReportNumbers::class);
		$numbers->method('fold')->willReturnCallback(static fn (array $records): array => $folds[$records[0]['portal']]);
		$availability = $this->createMock(originalClassName: AvailabilityReport::class);
		$availability->method('forPortal')->willReturnCallback(
			static function (string $portal, DateTimeImmutable $now, int $months) use (&$asked, $reports): array {
				$asked[] = 'avail ' . $portal . ' ' . $months;
				return $reports[$portal];
			}
		);

		return new PortalOverview($portals, $traffic, $numbers, $availability);
	}//end overview()
}//end class
