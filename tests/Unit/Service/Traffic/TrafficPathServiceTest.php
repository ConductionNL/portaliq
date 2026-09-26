<?php

/**
 * Unit tests for TrafficPathService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use DateTime;
use DateTimeZone;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficPaths;
use OCA\Portaliq\Service\Traffic\TrafficPathService;
use OCA\Portaliq\Service\Traffic\TrafficSegments;
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * What is read, how much of the period that was, and that the answer
 * says so. The clock stands at 2026-09-23 10:00 UTC throughout, so with
 * the default 90 days of retention the first kept day is 2026-06-26.
 */
class TrafficPathServiceTest extends TestCase {

	/**
	 * The days the store was asked for, newest first, with the limit.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $asked = [];

	/**
	 * What the fake cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];


	/**
	 * A service over a store that answers from `$days` (day => events),
	 * cutting each day at the limit it is given.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $days      The events per day.
	 * @param array<string, mixed>                            $traffic   The portal's traffic block.
	 * @param int                                             $maxEvents The event cap.
	 *
	 * @return TrafficPathService The service.
	 */
	private function service(array $days, array $traffic = [], int $maxEvents = 1000): TrafficPathService {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(
			[['slug' => 'open-tilburg', 'traffic' => array_merge(['enabled' => true], $traffic)]]
		);

		$store = $this->createMock(TrafficEventStore::class);
		$store->method('eventsForPaths')->willReturnCallback(
			function (string $portal, string $from, string $to, int $limit) use ($days): array {
				$day = substr($from, 0, 10);
				$this->asked[] = [$day, $limit];
				$events = ($days[$day] ?? []);

				return ['events' => array_slice($events, 0, $limit), 'truncated' => count($events) > $limit];
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;

				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-23 10:00:00', new DateTimeZone('UTC')));

		return new TrafficPathService(
			$portals,
			new TrafficConfigResolver(),
			$store,
			new TrafficSessioniser(),
			new TrafficSegments(),
			new TrafficPaths(),
			$factory,
			$time,
			$maxEvents
		);
	}//end service()


	/**
	 * One visit's page views on a day, a minute apart.
	 *
	 * @param string               $day   The day.
	 * @param string               $id    The session id.
	 * @param string[]             $paths The paths.
	 * @param array<string, mixed> $extra Fields every event carries.
	 *
	 * @return array<int, array<string, mixed>> The events.
	 */
	private function visit(string $day, string $id, array $paths, array $extra = []): array {
		$events = [];
		foreach ($paths as $i => $path) {
			$events[] = array_merge(
				[
					'name' => 'page_view',
					'sessionId' => $id,
					'sequence' => $i,
					'occurredAt' => sprintf('%sT09:%02d:00.000Z', $day, $i),
					'pagePath' => $path,
				],
				$extra
			);
		}

		return $events;
	}//end visit()


	/**
	 * The page paths of one step.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 * @param int                  $step   The step.
	 *
	 * @return array<string, int> Path => visits.
	 */
	private function step(array $result, int $step): array {
		$out = [];
		foreach ($result['columns'][$step]['nodes'] as $node) {
			$out[$node['path']] = $node['sessions'];
		}

		return $out;
	}//end step()


	/**
	 * A slug nobody publishes, and a segment the portal does not have, are
	 * refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnknownPortalOrSegmentIsRefusedWithoutARead(): void {
		$service = $this->service(days: []);

		$this->assertSame(['error' => 'unknown-portal'], $service->explore('open-breda', '2026-09-20', '2026-09-23', '', 'start', '', 3, []));
		$this->assertSame(['error' => 'unknown-segment'], $service->explore('open-tilburg', '2026-09-20', '2026-09-23', 'mobile', 'start', '', 3, []));
		$this->assertSame([], $this->asked);
	}//end testAnUnknownPortalOrSegmentIsRefusedWithoutARead()


	/**
	 * Visits are grouped by the sessioniser, so events that arrive out of
	 * order still make the path the visitor walked, and each visit counts
	 * once. Days are read newest first.
	 *
	 * @return void
	 */
	public function testPathsAreTheVisitsOwnOrderReadNewestDayFirst(): void {
		$day = array_reverse($this->visit(day: '2026-09-22', id: 's1', paths: ['/home', '/news', '/contact']));
		$service = $this->service(
			days: [
				'2026-09-22' => array_merge($day, $this->visit(day: '2026-09-22', id: 's2', paths: ['/about', '/news'])),
				'2026-09-23' => $this->visit(day: '2026-09-23', id: 's3', paths: ['/home']),
			]
		);

		$result = $service->explore('open-tilburg', '2026-09-21', '2026-09-23', '', 'start', '', 2, []);

		$this->assertSame([['2026-09-23', 1000], ['2026-09-22', 999], ['2026-09-21', 994]], $this->asked);
		$this->assertSame(3, $result['sessions']);
		$this->assertSame(3, $result['sessionsRead']);
		$this->assertSame(['/home' => 2, '/about' => 1], $this->step(result: $result, step: 0));
		$this->assertSame(['/news' => 2], $this->step(result: $result, step: 1));
		$this->assertSame(['/contact' => 1], $this->step(result: $result, step: 2));
		$this->assertFalse($result['truncated']);
		$this->assertSame(
			['from' => '2026-09-21', 'to' => '2026-09-23', 'keptFrom' => '2026-06-26', 'retentionDays' => 90, 'beyondRetention' => false, 'partialDay' => null],
			$result['coverage']
		);
	}//end testPathsAreTheVisitsOwnOrderReadNewestDayFirst()


	/**
	 * Scenario: a period beyond retention names the days covered. No day
	 * before the first kept day is asked for.
	 *
	 * @return void
	 */
	public function testAPeriodBeyondRetentionNamesTheDaysCovered(): void {
		$result = $this->service(days: [], traffic: ['retentionDays' => 30])->explore('open-tilburg', '2026-03-28', '2026-09-23', '', 'start', '', 3, []);

		$this->assertCount(30, $this->asked);
		$this->assertSame('2026-08-25', $this->asked[29][0]);
		$this->assertSame(
			['from' => '2026-08-25', 'to' => '2026-09-23', 'keptFrom' => '2026-08-25', 'retentionDays' => 30, 'beyondRetention' => true, 'partialDay' => null],
			$result['coverage']
		);
	}//end testAPeriodBeyondRetentionNamesTheDaysCovered()


	/**
	 * A period wholly before retention reads nothing and covers nothing.
	 *
	 * @return void
	 */
	public function testAPeriodWhollyBeforeRetentionCoversNothing(): void {
		$result = $this->service(days: [])->explore('open-tilburg', '2026-01-01', '2026-02-01', '', 'start', '', 3, []);

		$this->assertSame([], $this->asked);
		$this->assertNull($result['coverage']['from']);
		$this->assertTrue($result['coverage']['beyondRetention']);
		$this->assertSame(0, $result['sessions']);
	}//end testAPeriodWhollyBeforeRetentionCoversNothing()


	/**
	 * Scenario: a capped read says it is truncated. The day the cap fell on
	 * is partial and named; the older day is not read.
	 *
	 * @return void
	 */
	public function testACappedReadSaysItIsTruncated(): void {
		$service = $this->service(
			days: [
				'2026-09-23' => $this->visit(day: '2026-09-23', id: 'a', paths: ['/a', '/b', '/c']),
				'2026-09-22' => array_merge(
					$this->visit(day: '2026-09-22', id: 'b', paths: ['/x']),
					$this->visit(day: '2026-09-22', id: 'c', paths: ['/y', '/z'])
				),
				'2026-09-21' => $this->visit(day: '2026-09-21', id: 'd', paths: ['/q']),
			],
			maxEvents: 5
		);

		$result = $service->explore('open-tilburg', '2026-09-20', '2026-09-23', '', 'start', '', 1, []);

		$this->assertSame([['2026-09-23', 5], ['2026-09-22', 2]], $this->asked, '2026-09-21 is never read');
		$this->assertTrue($result['truncated']);
		$this->assertSame(5, $result['eventCap']);
		$this->assertSame(5, $result['eventsScanned']);
		$this->assertSame('2026-09-22', $result['coverage']['partialDay']);
		$this->assertSame('2026-09-22', $result['coverage']['from']);
		$this->assertSame(['/a' => 1, '/x' => 1, '/y' => 1], $this->step(result: $result, step: 0));
	}//end testACappedReadSaysItIsTruncated()


	/**
	 * A cap reached exactly at the end of a day leaves the older days
	 * unread and uncovered, and still says truncated.
	 *
	 * @return void
	 */
	public function testACapReachedAtTheEndOfADayCoversOnlyThatDay(): void {
		$service = $this->service(
			days: [
				'2026-09-23' => $this->visit(day: '2026-09-23', id: 'a', paths: ['/a', '/b']),
				'2026-09-22' => $this->visit(day: '2026-09-22', id: 'b', paths: ['/x']),
			],
			maxEvents: 2
		);

		$result = $service->explore('open-tilburg', '2026-09-20', '2026-09-23', '', 'start', '', 1, []);

		$this->assertSame([['2026-09-23', 2]], $this->asked);
		$this->assertTrue($result['truncated']);
		$this->assertNull($result['coverage']['partialDay']);
		$this->assertSame('2026-09-23', $result['coverage']['from']);
	}//end testACapReachedAtTheEndOfADayCoversOnlyThatDay()


	/**
	 * Scenario: a segment narrows the paths, by the same per-visit rule as
	 * the daily figures.
	 *
	 * @return void
	 */
	public function testASegmentNarrowsThePaths(): void {
		$service = $this->service(
			days: [
				'2026-09-23' => array_merge(
					$this->visit(day: '2026-09-23', id: 'm', paths: ['/home', '/news'], extra: ['deviceType' => 'mobile']),
					$this->visit(day: '2026-09-23', id: 'd', paths: ['/about'], extra: ['deviceType' => 'desktop'])
				),
			],
			traffic: ['segments' => [['id' => 'mobile', 'name' => 'Mobile', 'conditions' => [['dimension' => 'deviceType', 'operator' => 'is', 'value' => 'mobile']]]]]
		);

		$result = $service->explore('open-tilburg', '2026-09-23', '2026-09-23', 'mobile', 'start', '', 1, []);

		$this->assertSame(['/home' => 1], $this->step(result: $result, step: 0));
		$this->assertSame(1, $result['sessionsRead']);
		$this->assertSame('mobile', $result['segment']);
	}//end testASegmentNarrowsThePaths()


	/**
	 * A second question about the same portal, period and segment is
	 * answered from the cache, including a page path that looks like a
	 * number, which PHP turns into an integer key on the way through.
	 *
	 * @return void
	 */
	public function testTheSameReadIsAnsweredFromTheCache(): void {
		$service = $this->service(days: ['2026-09-23' => $this->visit(day: '2026-09-23', id: 'a', paths: ['0', '/b'])]);

		$first = $service->explore('open-tilburg', '2026-09-23', '2026-09-23', '', 'start', '', 1, []);
		$second = $service->explore('open-tilburg', '2026-09-23', '2026-09-23', '', 'end', '', 1, []);

		$this->assertCount(1, $this->asked, 'the events are read once');
		$this->assertSame(['0' => 1], $this->step(result: $first, step: 0));
		$this->assertSame(['/b' => 1], $this->step(result: $second, step: 0));
		$this->assertSame(['0' => 1], $this->step(result: $second, step: 1));
	}//end testTheSameReadIsAnsweredFromTheCache()
}//end class
