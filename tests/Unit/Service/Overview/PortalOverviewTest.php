<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Overview;

use DateTimeImmutable;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\Overview\PortalOverview;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's figures of all published portals together.
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */
class PortalOverviewTest extends TestCase {
	/**
	 * Traffic of the website and Mijn Zuiddrecht adds up, over the asked
	 * days, with the most viewed pages of both first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function testTrafficOfAllPortalsAddsUp(): void {
		$asked = [];
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);
		$traffic->method('dailyBetween')->willReturnCallback(
			function (string $portal, string $from, string $to) use (&$asked): array {
				$asked[] = [$portal, $from, $to];
				$afval    = ['path' => '/afval', 'views' => 40, 'entrances' => 10, 'exits' => 5, 'avgEngagementSeconds' => 3.0];
				$parkeren = ['path' => '/parkeren', 'views' => 20, 'entrances' => 4, 'exits' => 4, 'avgEngagementSeconds' => 2.0];
				$mijn     = ['path' => '/mijn', 'views' => 30, 'entrances' => 8, 'exits' => 8, 'avgEngagementSeconds' => 4.0];
				$days     = [
					'zuiddrecht' => ['pageViews' => 60, 'sessions' => 20, 'visitors' => 15, 'engagedSessions' => 12, 'pages' => [$afval, $parkeren]],
					'mijn-zuiddrecht' => ['pageViews' => 30, 'sessions' => 10, 'visitors' => 9, 'engagedSessions' => 6, 'pages' => [$mijn]],
				];
				return [['portal' => $portal, 'date' => $to] + $days[$portal]];
			}
		);

		$overview = $this->overview(traffic: $traffic)->traffic(days: 7, now: new DateTimeImmutable(datetime: '2026-10-07T09:00:00+00:00'));

		$this->assertSame(expected: [['zuiddrecht', '2026-10-01', '2026-10-07'], ['mijn-zuiddrecht', '2026-10-01', '2026-10-07']], actual: $asked);
		$figures = [$overview['pageViews'], $overview['sessions'], $overview['visitors'], $overview['engagedSessions'], $overview['engagedShare']];
		$this->assertSame(expected: [90, 30, 24, 18, 60], actual: $figures);
		$this->assertSame(expected: ['/afval', '/mijn', '/parkeren'], actual: array_column(array: $overview['pages'], column_key: 'path'));
		$this->assertSame(expected: 'Mijn Zuiddrecht', actual: $overview['pages'][1]['title']);
	}//end testTrafficOfAllPortalsAddsUp()

	/**
	 * No traffic reads as zeros and no engaged share, never a division by zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function testNoTrafficHasNoEngagedShare(): void {
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);
		$traffic->method('dailyBetween')->willReturn(value: []);

		$overview = $this->overview(traffic: $traffic)->traffic(days: 7, now: new DateTimeImmutable(datetime: '2026-10-07T09:00:00+00:00'));

		$this->assertSame(expected: 0, actual: $overview['visitors']);
		$this->assertNull(actual: $overview['engagedShare']);
		$this->assertSame(expected: [], actual: $overview['pages']);
	}//end testNoTrafficHasNoEngagedShare()

	/**
	 * Availability of last month per portal, both together, and the newest
	 * outage first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function testAvailabilityOfLastMonthPerPortalAndTogether(): void {
		$store = $this->createMock(originalClassName: AvailabilityStore::class);
		$available = ['zuiddrecht' => 1000, 'mijn-zuiddrecht' => 990];
		$store->method('dailyBetween')->willReturnCallback(
			static fn (string $portal): array => ['2026-09-10' => ['intervals' => 1000, 'available' => $available[$portal]]]
		);
		$outages = [
			'zuiddrecht' => [],
			'mijn-zuiddrecht' => [
				['startedAt' => '2026-09-03T10:00:00+00:00', 'durationMinutes' => 5],
				['startedAt' => '2026-09-22T10:00:00+00:00', 'durationMinutes' => 14],
			],
		];
		$store->method('outagesBetween')->willReturnCallback(static fn (string $portal): array => $outages[$portal]);
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);

		$now      = new DateTimeImmutable(datetime: '2026-10-07T09:00:00+00:00');
		$overview = $this->overview(traffic: $traffic, store: $store)->availability(now: $now);

		$this->assertSame(expected: '2026-09', actual: $overview['month']);
		$this->assertSame(expected: 99.5, actual: $overview['percentage']);
		$this->assertSame(expected: [100.0, 99.0], actual: array_column(array: $overview['portals'], column_key: 'percentage'));
		$this->assertSame(expected: [2, 19], actual: [$overview['outages'], $overview['outageMinutes']]);
		$this->assertSame(expected: '2026-09-22T10:00:00+00:00', actual: $overview['lastOutage']['startedAt']);
		$this->assertSame(expected: 'Mijn Zuiddrecht', actual: $overview['lastOutage']['title']);
	}//end testAvailabilityOfLastMonthPerPortalAndTogether()

	/**
	 * The overview over two published portals.
	 *
	 * @param TrafficEventStore $traffic The traffic records.
	 * @param AvailabilityStore|null $store The availability records.
	 *
	 * @return PortalOverview
	 */
	private function overview(TrafficEventStore $traffic, ?AvailabilityStore $store = null): PortalOverview {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(
			value: [
				['slug' => 'zuiddrecht', 'title' => 'Gemeente Zuiddrecht'],
				['slug' => 'mijn-zuiddrecht', 'title' => 'Mijn Zuiddrecht'],
				['title' => 'No slug'],
			]
		);
		if ($store === null) {
			$store = $this->createMock(originalClassName: AvailabilityStore::class);
		}

		return new PortalOverview(
			portals: $portals,
			traffic: $traffic,
			numbers: new TrafficReportNumbers(),
			availability: new AvailabilityReport(store: $store)
		);
	}//end overview()
}//end class
