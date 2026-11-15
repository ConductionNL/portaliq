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
 * How the overview combines portals: which portals count, how a page and a
 * share are read, and how outages of several portals are put together.
 */
#[CoversClass(PortalOverview::class)]
class PortalOverviewCombinedTest extends TestCase {
	public function testAPortalWithoutSlugIsLeftOutAndTheTitleFallsBackToTheSlug(): void {
		$asked = [];
		$out   = $this->overview(
			portals: [['title' => 'Geen slug'], ['slug' => '', 'title' => 'Leeg'], ['slug' => 'mijn']],
			folds: ['mijn' => ['pages' => [['path' => '/afval', 'views' => '5']]]],
			reports: [],
			asked: $asked
		)->traffic(days: 1, now: new DateTimeImmutable('2026-10-07T09:00:00Z'));

		$this->assertSame(['traffic mijn 2026-10-07 2026-10-07'], $asked);
		$this->assertSame([['path' => '/afval', 'portal' => 'mijn', 'title' => 'mijn', 'views' => 5]], $out['pages']);
	}//end testAPortalWithoutSlugIsLeftOutAndTheTitleFallsBackToTheSlug()

	public function testTheEngagedShareIsRoundedOverAllPortalsAndAPageWithoutPathReadsEmpty(): void {
		$asked = [];
		$out   = $this->overview(
			portals: [['slug' => 'a', 'title' => 'A'], ['slug' => 'b', 'title' => 'B']],
			folds: [
				'a' => ['sessions' => 2, 'engagedSessions' => 1, 'pageViews' => '4', 'pages' => [['views' => 3]]],
				'b' => ['sessions' => 1, 'engagedSessions' => 0, 'visitors' => 2],
			],
			reports: [],
			asked: $asked
		)->traffic(days: 2, now: new DateTimeImmutable('2026-10-07T09:00:00Z'));

		$this->assertSame(
			[
				'from' => '2026-10-06',
				'to' => '2026-10-07',
				'days' => 2,
				'pageViews' => 4,
				'sessions' => 3,
				'visitors' => 2,
				'engagedSessions' => 1,
				'engagedShare' => 33,
				'pages' => [['path' => '', 'portal' => 'a', 'title' => 'A', 'views' => 3]],
			],
			$out
		);
	}//end testTheEngagedShareIsRoundedOverAllPortalsAndAPageWithoutPathReadsEmpty()

	public function testOutagesOfAllPortalsAreNewestFirstAndTheirMinutesAddUp(): void {
		$asked = [];
		$out   = $this->overview(
			portals: [['slug' => 'a', 'title' => 'A'], ['slug' => 'b', 'title' => 'B']],
			folds: [],
			reports: [
				'a' => [
					'months' => [['month' => '2026-09', 'intervals' => 100, 'available' => 99, 'percentage' => 99.0]],
					'outages' => [['startedAt' => '2026-09-03T10:00:00Z', 'durationMinutes' => 15], ['startedAt' => '2026-09-20T08:00:00Z', 'durationMinutes' => '5']],
				],
				'b' => [
					'months' => [['month' => '2026-09', 'intervals' => 200, 'available' => 197]],
					'outages' => [['startedAt' => '2026-09-10T12:00:00Z']],
				],
			],
			asked: $asked
		)->availability(now: new DateTimeImmutable('2026-10-02T10:00:00Z'));

		$this->assertSame('2026-09', $out['month']);
		$this->assertSame(98.67, $out['percentage']);
		$this->assertSame(3, $out['outages']);
		$this->assertSame(20, $out['outageMinutes']);
		$this->assertSame(['startedAt' => '2026-09-20T08:00:00Z', 'durationMinutes' => '5', 'portal' => 'a', 'title' => 'A'], $out['lastOutage']);
		$this->assertSame(
			[['portal' => 'a', 'title' => 'A', 'percentage' => 99.0], ['portal' => 'b', 'title' => 'B', 'percentage' => null]],
			$out['portals']
		);
		$this->assertSame(['avail a 1', 'avail b 1'], $asked);
	}//end testOutagesOfAllPortalsAreNewestFirstAndTheirMinutesAddUp()

	public function testTheMonthIsTheLastPortalThatNamesOne(): void {
		$asked = [];
		$out   = $this->overview(
			portals: [['slug' => 'a', 'title' => 'A'], ['slug' => 'b', 'title' => 'B']],
			folds: [],
			reports: ['a' => ['months' => [['month' => '2026-09']]], 'b' => []],
			asked: $asked
		)->availability(now: new DateTimeImmutable('2026-10-02T10:00:00Z'));

		$this->assertSame('2026-09', $out['month']);
		$this->assertNull($out['percentage']);
		$this->assertNull($out['lastOutage']);
		$this->assertSame(0, $out['outageMinutes']);
	}//end testTheMonthIsTheLastPortalThatNamesOne()

	/**
	 * An overview over these portals and their folded traffic and availability.
	 *
	 * @param array<int, array<string, mixed>>    $portals The published portals.
	 * @param array<string, array<string, mixed>> $folds   Folded traffic per slug.
	 * @param array<string, array<string, mixed>> $reports Availability report per slug.
	 * @param array<int, string>                  $asked   Every store and report call, appended.
	 *
	 * @return PortalOverview
	 */
	private function overview(array $portals, array $folds, array $reports, array &$asked): PortalOverview {
		$published = $this->createMock(originalClassName: PublishedPortals::class);
		$published->method('allPublishedPortals')->willReturn($portals);
		$traffic = $this->createMock(originalClassName: TrafficEventStore::class);
		$traffic->method('dailyBetween')->willReturnCallback(
			static function (string $portal, string $from, string $to) use (&$asked): array {
				$asked[] = 'traffic ' . $portal . ' ' . $from . ' ' . $to;
				return [['portal' => $portal]];
			}
		);
		$numbers = $this->createMock(originalClassName: TrafficReportNumbers::class);
		$numbers->method('fold')->willReturnCallback(static fn (array $records): array => ($folds[$records[0]['portal']] ?? []));
		$availability = $this->createMock(originalClassName: AvailabilityReport::class);
		$availability->method('forPortal')->willReturnCallback(
			static function (string $portal, DateTimeImmutable $now, int $months) use (&$asked, $reports): array {
				$asked[] = 'avail ' . $portal . ' ' . $months;
				return ($reports[$portal] ?? []);
			}
		);

		return new PortalOverview($published, $traffic, $numbers, $availability);
	}//end overview()
}//end class
