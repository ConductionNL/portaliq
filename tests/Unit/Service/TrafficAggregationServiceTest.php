<?php

/**
 * Unit tests for TrafficAggregationService.
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

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTime;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficRollup;
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use OCA\Portaliq\Service\TrafficAggregationService;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCA\Portaliq\Tests\Unit\Service\Traffic\FakeAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The test tree is not autoloaded; the double is a plain include.
require_once __DIR__ . '/Traffic/FakeAppConfig.php';

/**
 * The job's contract: one rollup per portal-day, the same numbers on
 * every run, the raw events purged afterwards.
 *
 * The store is an in-memory fake: `eventsBetween` filters a fixed list by
 * portal and window, `findDaily`/`saveDaily` keep the rollups in an array
 * keyed by uuid, exactly like OpenRegister would.
 */
class TrafficAggregationServiceTest extends TestCase {

	/**
	 * The raw events the fake store holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * The rollups the fake store holds, uuid => record.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $daily = [];

	/**
	 * How many times purgeExpired() was called.
	 *
	 * @var int
	 */
	private int $purges = 0;

	/**
	 * The shared app config, so the watermark can be read back.
	 *
	 * @var FakeAppConfig
	 */
	private FakeAppConfig $config;

	/**
	 * The frozen clock: 2026-09-04 23:30:00 UTC.
	 */
	private const NOW = 1788564600;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events = [];
		$this->daily = [];
		$this->purges = 0;
		$this->config = new FakeAppConfig();
	}//end setUp()


	/**
	 * The service over the fake store and two portals, one of them not
	 * measuring (its rollups are still rebuilt from whatever it has: a
	 * portal that switched measurement off keeps its history).
	 *
	 * @return TrafficAggregationService The service.
	 */
	private function service(): TrafficAggregationService {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([
			['slug' => 'open-tilburg', 'status' => 'published', 'traffic' => ['enabled' => true, 'sessionTimeoutMinutes' => 30]],
			['slug' => 'open-venray', 'status' => 'published', 'traffic' => ['enabled' => false]],
		]);

		$store = $this->createMock(TrafficEventStore::class);
		$store->method('eventsBetween')->willReturnCallback(
			fn (string $portal, string $from, string $to): array => array_values(array_filter(
				$this->events,
				static fn (array $e): bool => $e['portal'] === $portal && $e['occurredAt'] >= $from && $e['occurredAt'] < $to
			))
		);
		$store->method('receivedSince')->willReturnCallback(
			fn (string $since): array => array_values(array_filter($this->events, static fn (array $e): bool => $e['receivedAt'] >= $since))
		);
		$store->method('findDaily')->willReturnCallback(
			function (string $portal, string $date): ?array {
				foreach ($this->daily as $uuid => $record) {
					if ($record['portal'] === $portal && $record['date'] === $date) {
						return ['@self' => ['uuid' => $uuid]] + $record;
					}
				}

				return null;
			}
		);
		$store->method('saveDaily')->willReturnCallback(
			function (array $rollup, ?string $uuid): bool {
				$this->daily[$uuid ?? ('uuid-' . (count($this->daily) + 1))] = $rollup;

				return true;
			}
		);
		$store->method('purgeExpired')->willReturnCallback(
			function (): int {
				$this->purges++;

				return 3;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);
		$clock->method('getDateTime')->willReturn(new DateTime('@' . self::NOW));

		return new TrafficAggregationService(
			$portals,
			new TrafficConfigResolver(),
			$store,
			new TrafficSessioniser(),
			new TrafficRollup(),
			$this->config->mock($this),
			$clock,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()


	/**
	 * One raw event.
	 *
	 * @param string $portal     The portal slug.
	 * @param string $occurredAt When it happened.
	 * @param string $path       The page path.
	 * @param string $receivedAt When the collector got it.
	 * @param string $visitor    The visitor hash.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $portal, string $occurredAt, string $path, string $receivedAt = '2026-09-04T23:00:00.000Z', string $visitor = 'h1'): array {
		return [
			'portal' => $portal,
			'name' => 'page_view',
			'occurredAt' => $occurredAt,
			'receivedAt' => $receivedAt,
			'sequence' => 0,
			'pagePath' => $path,
			'pageLocation' => 'https://x' . $path,
			'visitorHash' => $visitor,
		];
	}//end event()


	/**
	 * The rollups for one portal, date => record.
	 *
	 * @param string $portal The slug.
	 *
	 * @return array<string, array<string, mixed>> The rollups.
	 */
	private function rollupsFor(string $portal): array {
		$out = [];
		foreach ($this->daily as $record) {
			if ($record['portal'] === $portal) {
				$out[$record['date']] = $record;
			}
		}

		return $out;
	}//end rollupsFor()


	/**
	 * Running twice over the same raw events gives the same numbers and
	 * ONE rollup object per portal-day: the second run updated the first
	 * run's object instead of adding a second one.
	 *
	 * @return void
	 */
	public function testRunningTwiceGivesTheSameNumbersAndOneObjectPerDay(): void {
		$this->events = [
			$this->event('open-tilburg', '2026-09-04T10:00:00.000Z', '/'),
			$this->event('open-tilburg', '2026-09-04T10:00:30.000Z', '/begrippen'),
			$this->event('open-tilburg', '2026-09-03T15:00:00.000Z', '/', visitor: 'h2'),
			$this->event('open-venray', '2026-09-04T11:00:00.000Z', '/'),
		];
		$service = $this->service();

		$first = $service->run();
		$afterFirst = $this->daily;
		$second = $service->run();

		$this->assertSame(['portals' => 2, 'days' => 3, 'purged' => 3], $first);
		$this->assertSame(['portals' => 2, 'days' => 3, 'purged' => 3], $second);
		$this->assertCount(3, $this->daily, 'one object per portal-day, not one per run');
		$this->assertSame($afterFirst, $this->daily, 'the second run replaced, it did not add');

		$tilburg = $this->rollupsFor('open-tilburg');
		$this->assertSame(2, $tilburg['2026-09-04']['pageViews']);
		$this->assertSame(1, $tilburg['2026-09-04']['sessions']);
		$this->assertSame(1, $tilburg['2026-09-03']['pageViews']);
		$this->assertSame(1, $this->rollupsFor('open-venray')['2026-09-04']['pageViews']);
		$this->assertSame(2, $this->purges, 'expired raw events are purged on every run');
	}//end testRunningTwiceGivesTheSameNumbersAndOneObjectPerDay()


	/**
	 * A day with no raw events is not rewritten: its rollup survives the
	 * purge of the events it was computed from.
	 *
	 * @return void
	 */
	public function testADayWithNoRawEventsKeepsItsRollup(): void {
		$this->daily['keep'] = ['portal' => 'open-tilburg', 'date' => '2026-09-03', 'pageViews' => 99];
		$this->events = [$this->event('open-tilburg', '2026-09-04T10:00:00.000Z', '/')];

		$this->service()->run();

		$this->assertSame(99, $this->daily['keep']['pageViews']);
		$this->assertCount(2, $this->daily);
	}//end testADayWithNoRawEventsKeepsItsRollup()


	/**
	 * A late batch names its own day: an event for last week received
	 * after the watermark lands in last week's rollup, not today's.
	 *
	 * @return void
	 */
	public function testALateEventIsCountedUnderItsOwnDay(): void {
		$this->events = [$this->event('open-tilburg', '2026-09-04T10:00:00.000Z', '/', receivedAt: '2026-09-04T10:00:01.000Z')];
		$service = $this->service();
		$service->run();
		$this->assertArrayNotHasKey('2026-08-28', $this->rollupsFor('open-tilburg'));

		// Received after the first run's watermark (23:30), dated a week ago.
		$this->events[] = $this->event('open-tilburg', '2026-08-28T09:00:00.000Z', '/late', receivedAt: '2026-09-04T23:31:00.000Z');
		$service->run();

		$late = $this->rollupsFor('open-tilburg')['2026-08-28'] ?? null;
		$this->assertNotNull($late, 'the late event opened its own day');
		$this->assertSame(1, $late['pageViews']);
		$this->assertSame('/late', $late['pages'][0]['path']);
		$this->assertSame(1, $this->rollupsFor('open-tilburg')['2026-09-04']['pageViews'], 'and did not leak into today');
		$this->assertSame('2026-09-04T23:30:00Z', $this->config->values['portaliq/traffic_aggregated_open-tilburg']);
	}//end testALateEventIsCountedUnderItsOwnDay()
}//end class
