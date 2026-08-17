<?php

/**
 * Portaliq Traffic Maintenance Test
 *
 * That the summary is written before the evidence is deleted, and that running
 * the sweep twice does not double a figure.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalTrafficAggregator;
use OCA\Portaliq\Service\PortalTrafficReporter;
use OCA\Portaliq\Service\PortalUnscopedStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The retention sweep.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficMaintenanceTest extends TestCase {

	/**
	 * Every call the writer received, in order, as `verb:schema`.
	 *
	 * THE ORDER IS THE ASSERTION. Deleting raw events before their summary is
	 * durable would lose a day permanently on a crash between the two, and no
	 * assertion about the final state can see that — only the sequence can.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Aggregates the writer was asked to store, by day.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Ids the writer was asked to delete.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Aggregate rows the writer already holds, as OR would return them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $storedAggregates = [];

	/**
	 * Whether the aggregate write should fail.
	 */
	private bool $writeFails = false;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = [];
		$this->written = [];
		$this->deleted = [];
		$this->storedAggregates = [];
		$this->writeFails = false;
	}//end setUp()


	/**
	 * A day's worth of events for one visitor.
	 *
	 * @return array<int, array<string, mixed>> The events.
	 */
	private function events(): array {
		return [
			[
				'id' => 'e1',
				'name' => 'page_view',
				'clientId' => 'c-1',
				'sessionId' => 's-1',
				'sequence' => 0,
				'pageLocation' => '/',
				'receivedAt' => '2026-08-17T10:00:00+00:00',
			],
			[
				'id' => 'e2',
				'name' => 'page_view',
				'clientId' => 'c-1',
				'sessionId' => 's-1',
				'sequence' => 1,
				'pageLocation' => '/diensten',
				'receivedAt' => '2026-08-17T10:01:00+00:00',
			],
		];
	}//end events()


	/**
	 * The portal under test.
	 *
	 * @param int $retentionDays How long raw events are kept.
	 *
	 * @return array<string, mixed> The portal.
	 */
	private function portal(int $retentionDays = 90): array {
		return [
			'slug' => 'demo',
			'traffic' => [
				'enabled' => true,
				'events' => ['page_view'],
				'retentionDays' => $retentionDays,
			],
		];
	}//end portal()


	/**
	 * A service over a writer that records the sequence of calls.
	 *
	 * @return PortalTrafficReporter The reporter.
	 */
	private function service(): PortalTrafficReporter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$store = $this->createMock(PortalUnscopedStore::class);

		$store->method('readObjects')->willReturnCallback(
			function (string $register, string $schema): array {
				$this->calls[] = ('read:' . $schema);
				if ($schema === 'portalTrafficAggregate') {
					return $this->storedAggregates;
				}

				return $this->events();
			}
		);

		$writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data, string $uuid = ''): ?array {
				$this->calls[] = ('write:' . $schema . ($uuid !== '' ? ':update' : ':create'));
				if ($this->writeFails === true) {
					return null;
				}

				$this->written[(string)$data['day']] = $data;
				return $data;
			}
		);

		$store->method('deleteObjects')->willReturnCallback(
			function (string $register, string $schema, array $ids): int {
				$this->calls[] = ('delete:' . $schema);
				foreach ($ids as $id) {
					$this->deleted[] = $id;
				}

				return count($ids);
			}
		);

		return new PortalTrafficReporter($writer, $store, new PortalTrafficAggregator());
	}//end service()


	/**
	 * THE SUMMARY IS WRITTEN BEFORE ANYTHING IS DELETED.
	 *
	 * Asserted on the call SEQUENCE rather than the end state, because the end
	 * state is identical either way and only the order says whether a crash
	 * between the two steps loses a day.
	 *
	 * @return void
	 */
	public function testTheAggregateIsWrittenBeforeAnyEventIsDeleted(): void {
		// Events a hundred days old against a ninety-day window, so the sweep
		// genuinely has something to delete.
		$this->service()->runMaintenance(portal: $this->portal(), now: strtotime('2026-11-25T00:00:00+00:00'));

		$writeAt = array_search('write:portalTrafficAggregate:create', $this->calls, true);
		$deleteAt = array_search('delete:portalTrafficEvent', $this->calls, true);

		$this->assertNotFalse($writeAt, 'no aggregate was written at all');
		$this->assertNotFalse($deleteAt, 'nothing was swept');
		$this->assertLessThan($deleteAt, $writeAt, 'raw events were deleted before their summary was durable');
	}//end testTheAggregateIsWrittenBeforeAnyEventIsDeleted()


	/**
	 * A FAILED SUMMARY DELETES NOTHING.
	 *
	 * The stronger half of the ordering guarantee: writing first is only
	 * protective if a write that FAILS also stops the sweep. Otherwise the
	 * order is decorative and the day is lost anyway.
	 *
	 * @return void
	 */
	public function testAFailedAggregateWriteCancelsTheSweep(): void {
		$this->writeFails = true;

		$this->service()->runMaintenance(portal: $this->portal(), now: strtotime('2026-11-25T00:00:00+00:00'));

		$this->assertNotContains('delete:portalTrafficEvent', $this->calls);
		$this->assertSame([], $this->deleted);
	}//end testAFailedAggregateWriteCancelsTheSweep()


	/**
	 * A second run REPLACES the day rather than adding a second row.
	 *
	 * A job that appends would double every figure on each run, which is the
	 * failure that looks like growth.
	 *
	 * @return void
	 */
	public function testASecondRunUpdatesTheSameDayRatherThanAppending(): void {
		$now = strtotime('2026-08-18T00:00:00+00:00');
		$this->service()->runMaintenance(portal: $this->portal(), now: $now);

		$this->assertContains('write:portalTrafficAggregate:create', $this->calls);
		$first = $this->written['2026-08-17'];

		// What the first run left behind, as OR would hand it back.
		$this->storedAggregates = [['id' => 'agg-1', 'day' => '2026-08-17']];
		$this->calls = [];

		$this->service()->runMaintenance(portal: $this->portal(), now: $now);

		$this->assertContains('write:portalTrafficAggregate:update', $this->calls);
		$this->assertNotContains('write:portalTrafficAggregate:create', $this->calls);

		// And the figures are the same, not doubled.
		$second = $this->written['2026-08-17'];
		$this->assertSame($first['sessions'], $second['sessions']);
		$this->assertSame($first['pageViews'], $second['pageViews']);
	}//end testASecondRunUpdatesTheSameDayRatherThanAppending()


	/**
	 * Recent events are summarised and KEPT.
	 *
	 * @return void
	 */
	public function testEventsInsideTheWindowAreSummarisedButNotDeleted(): void {
		$this->service()->runMaintenance(portal: $this->portal(), now: strtotime('2026-08-18T00:00:00+00:00'));

		$this->assertArrayHasKey('2026-08-17', $this->written);
		$this->assertSame([], $this->deleted, 'an event inside the retention window was deleted');
	}//end testEventsInsideTheWindowAreSummarisedButNotDeleted()


	/**
	 * A portal that does not measure is left entirely alone.
	 *
	 * Not merely "collects nothing" — it must not read, write or delete
	 * anything either. A maintenance job that sweeps portals which never opted
	 * in is deleting data on behalf of an operator who never asked to be
	 * measured.
	 *
	 * @return void
	 */
	public function testAnUnmeasuredPortalIsNotTouchedAtAll(): void {
		$result = $this->service()->runMaintenance(portal: ['slug' => 'demo'], now: 1786000000);

		$this->assertSame(['days' => 0, 'deleted' => 0], $result);
		$this->assertSame([], $this->calls);
	}//end testAnUnmeasuredPortalIsNotTouchedAtAll()


}//end class
