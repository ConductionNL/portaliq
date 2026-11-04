<?php

/**
 * TrafficPathExploreGoldenTest
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
 * The path explorer's whole answer, the days it reads with their limits and
 * what it caches, pinned per request over one fixed event set.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficPathService
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficPaths
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSegments
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSessioniser
 * @uses   \OCA\Portaliq\Service\TrafficConfigResolver
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficMatch
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficPagePath
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficReportDefinitions
 */
class TrafficPathExploreGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-path-explore.json';

	/**
	 * The days read, with their limits.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $asked = [];

	/**
	 * The cache, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * Every request's answer, reads and cache writes match the recorded ones.
	 *
	 * @return void
	 */
	public function testEveryExploreMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->requests() as $name => [$options, $calls]) {
			$this->asked  = [];
			$this->cached = [];
			$service      = $this->service(traffic: $options['traffic'], maxEvents: $options['maxEvents']);
			$answers      = [];
			foreach ($calls as $call) {
				$answers[] = $service->explore(...$call);
			}

			$actual[$name] = ['answers' => $answers, 'asked' => $this->asked, 'cached' => array_values($this->cached)];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryExploreMatchesTheGolden()

	/**
	 * Requests by name: the service options and the explore calls.
	 *
	 * @return array<string, array{0: array{traffic: array<string, mixed>, maxEvents: int}, 1: array<int, array<int, mixed>>}>
	 */
	private function requests(): array {
		$plain   = ['traffic' => [], 'maxEvents' => 1000];
		$goal    = ['traffic' => ['goals' => [['id' => 'contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact']]], 'segments' => [['id' => 'converted', 'name' => 'Converted', 'conditions' => [['dimension' => 'goal:contact', 'operator' => 'is', 'value' => 'true']]]]], 'maxEvents' => 1000];
		$segment = ['traffic' => ['segments' => [['id' => 'mobile', 'name' => 'Mobile', 'conditions' => [['dimension' => 'deviceType', 'operator' => 'is', 'value' => 'mobile']]]]], 'maxEvents' => 1000];

		return [
			'unknown portal'      => [$plain, [['elders', '2026-09-20', '2026-09-23', '', 'start', '', 2, []]]],
			'unknown segment'     => [$plain, [['open-tilburg', '2026-09-20', '2026-09-23', 'nope', 'start', '', 2, []]]],
			'three days'          => [$plain, [['open-tilburg', '2026-09-20', '2026-09-23', '', 'start', '', 2, []]]],
			'end mode, anchor'    => [$plain, [['open-tilburg', '2026-09-20', '2026-09-23', '', 'end', '/news', 2, ['/news']]]],
			'trail'               => [$plain, [['open-tilburg', '2026-09-20', '2026-09-23', '', 'start', '', 3, [1 => '/news', 2 => null]]]],
			'future to'           => [$plain, [['open-tilburg', '2026-09-22', '2026-10-30', '', 'start', '', 1, []]]],
			'beyond retention'    => [['traffic' => ['retentionDays' => 3], 'maxEvents' => 1000], [['open-tilburg', '2026-09-01', '2026-09-23', '', 'start', '', 1, []]]],
			'before retention'    => [$plain, [['open-tilburg', '2026-01-01', '2026-02-01', '', 'start', '', 1, []]]],
			'capped mid-day'      => [['traffic' => [], 'maxEvents' => 4], [['open-tilburg', '2026-09-19', '2026-09-23', '', 'start', '', 1, []]]],
			'capped at day end'   => [['traffic' => [], 'maxEvents' => 3], [['open-tilburg', '2026-09-19', '2026-09-23', '', 'start', '', 1, []]]],
			'segment'             => [$segment, [['open-tilburg', '2026-09-20', '2026-09-23', 'mobile', 'start', '', 1, []]]],
			'cached second read'  => [$plain, [['open-tilburg', '2026-09-22', '2026-09-23', '', 'start', '', 1, []], ['open-tilburg', '2026-09-22', '2026-09-23', '', 'end', '', 2, []]]],
			'from at kept edge'   => [['traffic' => ['retentionDays' => 3], 'maxEvents' => 1000], [['open-tilburg', '2026-09-21', '2026-09-23', '', 'start', '', 1, []]]],
			'segment then all'    => [$segment, [['open-tilburg', '2026-09-22', '2026-09-23', 'mobile', 'start', '', 1, []], ['open-tilburg', '2026-09-22', '2026-09-23', '', 'start', '', 1, []]]],
			'goal segment'        => [$goal, [['open-tilburg', '2026-09-20', '2026-09-23', 'converted', 'start', '', 2, []]]],
			'other timeout'       => [['traffic' => ['sessionTimeoutMinutes' => 1], 'maxEvents' => 1000], [['open-tilburg', '2026-09-22', '2026-09-23', '', 'start', '', 1, []]]],
		];
	}//end requests()

	/**
	 * The service over a fixed event set, a recording store and an array cache.
	 *
	 * @param array<string, mixed> $traffic   The portal's traffic configuration.
	 * @param int                  $maxEvents The event cap.
	 *
	 * @return TrafficPathService
	 */
	private function service(array $traffic, int $maxEvents): TrafficPathService {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(
			['not a portal', ['slug' => 'open-tilburg', 'traffic' => array_merge(['enabled' => true], $traffic)]]
		);

		$days  = $this->days();
		$store = $this->createMock(originalClassName: TrafficEventStore::class);
		$store->method('eventsForPaths')->willReturnCallback(
			function (string $portal, string $from, string $to, int $limit) use ($days): array {
				$day           = substr($from, 0, 10);
				$this->asked[] = [$day, $limit, $from, $to];
				$events        = ($days[$day] ?? []);

				return ['events' => array_slice($events, 0, $limit), 'truncated' => count($events) > $limit];
			}
		);

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;

				return true;
			}
		);
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-23 10:00:00', new DateTimeZone('UTC')));

		return new TrafficPathService(
			portals: $portals,
			config: new TrafficConfigResolver(),
			store: $store,
			sessioniser: new TrafficSessioniser(),
			segments: new TrafficSegments(),
			paths: new TrafficPaths(),
			cacheFactory: $factory,
			time: $time,
			maxEvents: $maxEvents
		);
	}//end service()

	/**
	 * The events per day.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function days(): array {
		return [
			'2026-09-23' => array_merge($this->visit(day: '2026-09-23', id: 'a', paths: ['/home', '/news', '/contact'], device: 'mobile'), $this->visit(day: '2026-09-23', id: 'b', paths: ['/about'], device: 'desktop')),
			'2026-09-22' => array_merge($this->visit(day: '2026-09-22', id: 'c', paths: ['/news', '/home'], device: 'mobile'), $this->visit(day: '2026-09-22', id: 'd', paths: ['/home', '/news'], device: 'desktop')),
			'2026-09-21' => $this->visit(day: '2026-09-21', id: 'e', paths: ['/home'], device: 'mobile'),
			'2026-09-20' => $this->visit(day: '2026-09-20', id: 'f', paths: ['/x', '/y', '/z'], device: 'desktop'),
		];
	}//end days()

	/**
	 * One visit's page views, a minute apart.
	 *
	 * @param string             $day    The day.
	 * @param string             $id     The session id.
	 * @param array<int, string> $paths  The pages in order.
	 * @param string             $device The device type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function visit(string $day, string $id, array $paths, string $device): array {
		$events = [];
		foreach ($paths as $i => $path) {
			$events[] = ['name' => 'page_view', 'sessionId' => $id, 'sequence' => $i, 'occurredAt' => sprintf('%sT09:%02d:00.000Z', $day, ($i * 3)), 'pagePath' => $path, 'deviceType' => $device];
		}

		return $events;
	}//end visit()
}//end class
