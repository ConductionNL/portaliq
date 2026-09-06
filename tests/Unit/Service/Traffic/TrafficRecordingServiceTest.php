<?php

/**
 * Unit tests for TrafficRecordingService.
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
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCA\Portaliq\Service\Traffic\TrafficRecordingMask;
use OCA\Portaliq\Service\Traffic\TrafficRecordingService;
use OCA\Portaliq\Service\Traffic\TrafficRecordingStore;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeAppConfig.php';

/**
 * The four gates, the two budgets, and what a stored recording holds.
 */
class TrafficRecordingServiceTest extends TestCase {

	/**
	 * What the fake store holds, by recording id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The counters.
	 *
	 * @var FakeAppConfig
	 */
	private FakeAppConfig $config;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [];
		$this->config = new FakeAppConfig();
	}//end setUp()


	/**
	 * The service over a fake store that remembers what it was given.
	 *
	 * @return TrafficRecordingService The service.
	 */
	private function service(): TrafficRecordingService {
		$store = $this->createMock(TrafficRecordingStore::class);
		$store->method('find')->willReturnCallback(fn (string $portal, string $id): ?array => $this->stored[$id] ?? null);
		$store->method('uuidOf')->willReturnCallback(static fn (array $row): string => (string)($row['@self']['uuid'] ?? ''));
		$store->method('save')->willReturnCallback(
			function (array $recording, ?string $uuid): bool {
				$this->stored[(string)$recording['recordingId']] = ['@self' => ['uuid' => $uuid ?? 'u-' . count($this->stored)]] + $recording;

				return true;
			}
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-05T10:00:00Z'));

		return new TrafficRecordingService(
			new TrafficConfigResolver(),
			$store,
			new TrafficRecordingMask(),
			new TrafficMetrics($this->config->mock($this)),
			$clock
		);
	}//end service()


	/**
	 * A portal with recording on.
	 *
	 * @param array<string, mixed> $traffic Overrides on the traffic block.
	 * @param array<string, mixed> $extra   Overrides on the portal.
	 *
	 * @return array<string, mixed> The portal.
	 */
	private function portal(array $traffic = [], array $extra = []): array {
		return $extra + [
			'slug' => 'open-tilburg',
			'traffic' => $traffic + ['enabled' => true, 'retentionDays' => 10, 'sensitive' => ['sessionRecording' => true]],
		];
	}//end portal()


	/**
	 * A chunk with one snapshot.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed> The chunk.
	 */
	private function chunk(array $extra = []): array {
		return $extra + [
			'recording' => 'abcdef0123456789abcdef0123456789',
			'page' => '/contact?bsn=1',
			'elapsed' => 1500,
			'events' => [['k' => 's', 't' => 0, 'w' => 800, 'h' => 600, 'n' => ['n' => 'body', 'c' => [['l' => 4, 'text' => 'Jan!']]]]],
		];
	}//end chunk()


	/**
	 * Off, external and pre-consent each refuse under their own reason,
	 * and nothing is stored.
	 *
	 * @return void
	 */
	public function testTheGatesRefuseWithTheirOwnReasons(): void {
		$service = $this->service();

		$off = $service->ingest(portal: $this->portal(['sensitive' => []]), body: $this->chunk(), context: ['consent' => true]);
		$this->assertSame(['ok' => false, 'reason' => 'sensitive-off'], $off);

		$external = $service->ingest(portal: $this->portal([], ['kind' => 'external']), body: $this->chunk(), context: ['consent' => true]);
		$this->assertSame(['ok' => false, 'reason' => 'external-portal'], $external);

		$consent = $service->ingest(
			portal: $this->portal(['consent' => ['required' => true]]),
			body: $this->chunk(),
			context: ['consent' => false]
		);
		$this->assertSame(['ok' => false, 'reason' => 'event-requires-consent'], $consent);

		$disabled = $service->ingest(portal: $this->portal(['enabled' => false]), body: $this->chunk(), context: ['consent' => true]);
		$this->assertSame('measurement-disabled', $disabled['reason']);

		$malformed = $service->ingest(portal: $this->portal(), body: $this->chunk(['recording' => 'Jan']), context: ['consent' => true]);
		$this->assertSame('malformed-recording', $malformed['reason']);

		$this->assertSame([], $this->stored);
	}//end testTheGatesRefuseWithTheirOwnReasons()


	/**
	 * A stored recording carries the masked stream, the page path without
	 * its query, the retention, and a second chunk appends in place.
	 *
	 * @return void
	 */
	public function testAChunkIsMaskedAndAppendedInPlace(): void {
		$service = $this->service();

		$first = $service->ingest(portal: $this->portal(), body: $this->chunk(), context: ['consent' => true]);
		$this->assertSame(['ok' => true, 'reason' => ''], $first);

		$record = $this->stored['abcdef0123456789abcdef0123456789'];
		$this->assertSame('open-tilburg', $record['portal']);
		$this->assertSame('2026-09-05T10:00:00.000Z', $record['startedAt']);
		$this->assertSame('2026-09-15T10:00:00Z', $record['expires'], 'started plus the portal\'s retention');
		$this->assertSame(['/contact'], $record['pages']);
		$this->assertSame(1500, $record['durationMs']);
		$this->assertCount(1, $record['chunks']);
		$this->assertStringNotContainsString('Jan', (string)json_encode($record));
		$this->assertSame([['l' => 4]], $record['chunks'][0]['events'][0]['n']['c']);

		$second = $service->ingest(
			portal: $this->portal(),
			body: $this->chunk(['page' => '/', 'elapsed' => 9000, 'events' => [['k' => 'c', 't' => 9000, 'x' => 1, 'y' => 2]]]),
			context: ['consent' => true]
		);
		$this->assertTrue($second['ok']);
		$record = $this->stored['abcdef0123456789abcdef0123456789'];
		$this->assertSame('u-0', $record['@self']['uuid'], 'the same object was updated');
		$this->assertCount(2, $record['chunks']);
		$this->assertSame(['/contact', '/'], $record['pages']);
		$this->assertSame(9000, $record['durationMs']);
		$this->assertGreaterThan(0, $record['bytes']);
	}//end testAChunkIsMaskedAndAppendedInPlace()


	/**
	 * A chunk over 256 KB and a visit over 2 MB are refused and counted.
	 *
	 * @return void
	 */
	public function testTheBudgetsRefuseAndCount(): void {
		$service = $this->service();
		$big = ['k' => 's', 't' => 0, 'w' => 1, 'h' => 1, 'n' => ['n' => 'div', 'a' => ['class' => str_repeat('x', 500)], 'c' => []]];
		$tooLarge = $service->ingest(
			portal: $this->portal(),
			body: $this->chunk(['events' => array_fill(0, 600, $big)]),
			context: ['consent' => true]
		);
		$this->assertSame('recording-chunk-too-large', $tooLarge['reason']);

		$this->stored['abcdef0123456789abcdef0123456789'] = [
			'@self' => ['uuid' => 'u-full'],
			'portal' => 'open-tilburg',
			'recordingId' => 'abcdef0123456789abcdef0123456789',
			'bytes' => TrafficRecordingService::MAX_RECORDING_BYTES - 10,
			'chunks' => [],
			'pages' => [],
		];
		$full = $service->ingest(portal: $this->portal(), body: $this->chunk(), context: ['consent' => true]);
		$this->assertSame('recording-full', $full['reason']);
		$this->assertSame([], $this->stored['abcdef0123456789abcdef0123456789']['chunks'], 'nothing appended');

		$refused = (new TrafficMetrics($this->config->mock($this)))->refusedByReason();
		$this->assertSame(1, $refused['recording-chunk-too-large'] ?? 0);
		$this->assertSame(1, $refused['recording-full'] ?? 0);
	}//end testTheBudgetsRefuseAndCount()
}//end class
