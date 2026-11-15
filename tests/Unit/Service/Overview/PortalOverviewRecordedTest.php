<?php

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
 * The overview's traffic and availability, folded from recorded per-portal answers.
 */
#[CoversClass(PortalOverview::class)]
class PortalOverviewRecordedTest extends TestCase {
	/**
	 * Traffic adds up per portal, ranks every page and asks each portal for the window, exactly as recorded.
	 *
	 * @return void
	 */
	public function testTrafficIsAsRecorded(): void {
		$asked = [];
		$out   = $this->overview(asked: $asked)->traffic(days: 3, now: new DateTimeImmutable('2026-09-10T23:30:00+02:00'));

		$this->assertSame(['a 2026-09-08 2026-09-10', 'b 2026-09-08 2026-09-10', 'c 2026-09-08 2026-09-10'], $asked);
		$this->assertSame(
			[
				'from' => '2026-09-08',
				'to' => '2026-09-10',
				'days' => 3,
				'pageViews' => 17,
				'sessions' => 7,
				'visitors' => 5,
				'engagedSessions' => 2,
				'engagedShare' => 29,
				'pages' => [
					['path' => '/b', 'portal' => 'b', 'title' => 'b', 'views' => 9],
					['path' => '/a1', 'portal' => 'a', 'title' => 'Alpha', 'views' => 5],
					['path' => '', 'portal' => 'a', 'title' => 'Alpha', 'views' => 5],
					['path' => '/a2', 'portal' => 'a', 'title' => 'Alpha', 'views' => 2],
				],
			],
			$out
		);
	}//end testTrafficIsAsRecorded()

	/**
	 * Availability takes the last month, adds the checks and orders the outages, exactly as recorded.
	 *
	 * @return void
	 */
	public function testAvailabilityIsAsRecorded(): void {
		$asked = [];
		$out   = $this->overview(asked: $asked)->availability(now: new DateTimeImmutable('2026-09-10T10:00:00Z'));

		$this->assertSame(
			[
				'month' => '2026-08',
				'percentage' => 97.5,
				'outages' => 3,
				'outageMinutes' => 45,
				'lastOutage' => ['startedAt' => '2026-08-20T10:00:00Z', 'durationMinutes' => 30, 'portal' => 'b', 'title' => 'b'],
				'portals' => [
					['portal' => 'a', 'title' => 'Alpha', 'percentage' => 99.0],
					['portal' => 'b', 'title' => 'b', 'percentage' => null],
					['portal' => 'c', 'title' => 'Gamma', 'percentage' => 96.0],
				],
			],
			$out
		);
		$this->assertSame(['avail a 1', 'avail b 1', 'avail c 1'], $asked);
	}//end testAvailabilityIsAsRecorded()

	/**
	 * The overview over three portals, a blank one and one without a slug.
	 *
	 * @param array<int, string> $asked Every store and report call, appended.
	 *
	 * @return PortalOverview
	 */
	private function overview(array &$asked): PortalOverview {
		$portals = $this->createMock(originalClassName: PublishedPortals::class);
		$portals->method('allPublishedPortals')->willReturn(
			value: [
				['slug' => 'a', 'title' => 'Alpha'],
				['slug' => '', 'title' => 'Blank'],
				['title' => 'None'],
				['slug' => 'b'],
				['slug' => 'c', 'title' => 'Gamma'],
			]
		);
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);
		$traffic->method('dailyBetween')->willReturnCallback(
			static function (string $portal, string $from, string $to) use (&$asked): array {
				$asked[] = $portal . ' ' . $from . ' ' . $to;
				return [['portal' => $portal]];
			}
		);
		$folds   = [
			'a' => ['pageViews' => 8, 'sessions' => 4, 'visitors' => '3', 'engagedSessions' => 1, 'pages' => [['path' => '/a1', 'views' => 5], ['views' => '5'], ['path' => '/a2', 'views' => 2]]],
			'b' => ['pageViews' => 9, 'sessions' => 3, 'visitors' => 2, 'engagedSessions' => 1, 'pages' => [['path' => '/b', 'views' => 9]]],
			'c' => ['pageViews' => 0],
		];
		$numbers = $this->createMock(originalClassName: TrafficReportNumbers::class);
		$numbers->method('fold')->willReturnCallback(static fn (array $records): array => $folds[$records[0]['portal']]);
		$reports = [
			'a' => ['months' => [['month' => '2026-08', 'intervals' => 100, 'available' => 99, 'percentage' => 99.0]], 'outages' => [['startedAt' => '2026-08-01T10:00:00Z', 'durationMinutes' => 10]]],
			'b' => ['months' => [], 'outages' => [['startedAt' => '2026-08-20T10:00:00Z', 'durationMinutes' => 30]]],
			'c' => ['months' => [['month' => '2026-08', 'intervals' => 100, 'available' => 96, 'percentage' => 96.0]], 'outages' => [['startedAt' => '2026-08-05T10:00:00Z', 'durationMinutes' => 5]]],
		];
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
