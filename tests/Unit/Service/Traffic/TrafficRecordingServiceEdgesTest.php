<?php

/**
 * Unit tests for TrafficRecordingService on malformed chunks and failing storage.
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
 * Pins the refusals for a chunk with no usable events, an events list that is
 * not a list, and a failed save; that an existing recording without a uuid is
 * saved as new; and how a non-string session id or page and a float, infinite
 * or text elapsed time are stored.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRecordingService
 * @uses   \OCA\Portaliq\Service\TrafficConfigResolver
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficRecordingMask
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficMetrics
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficReportDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSegments
 */
class TrafficRecordingServiceEdgesTest extends TestCase {

	private const RECORDING = 'abcdef0123456789abcdef0123456789';

	/**
	 * Every save: [recording, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saves = [];

	/**
	 * The metrics' app config.
	 *
	 * @var FakeAppConfig
	 */
	private FakeAppConfig $config;

	/**
	 * Fresh state per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saves = [];
		$this->config = new FakeAppConfig();
	}//end setUp()

	/**
	 * A chunk whose events the mask drops entirely is refused as empty and nothing is saved.
	 *
	 * @return void
	 */
	public function testAChunkWithNoUsableEventIsRefusedAsEmpty(): void {
		$result = $this->service()->ingest(portal: $this->portal(), body: $this->chunk(['events' => ['not an event', 42]]));

		$this->assertSame(['ok' => false, 'reason' => 'recording-empty'], $result);
		$this->assertSame([], $this->saves);
	}//end testAChunkWithNoUsableEventIsRefusedAsEmpty()

	/**
	 * Events that are not a list, or an empty list, are a malformed recording.
	 *
	 * @return void
	 */
	public function testEventsThatAreNotAListAreMalformed(): void {
		$service = $this->service();
		foreach (['a string' => 'events', 'empty' => [], 'missing' => null] as $name => $events) {
			$body = $this->chunk();
			$body['events'] = $events;
			if ($events === null) {
				unset($body['events']);
			}

			$this->assertSame(['ok' => false, 'reason' => 'malformed-recording'], $service->ingest(portal: $this->portal(), body: $body), $name);
		}

		$this->assertSame([], $this->saves);
	}//end testEventsThatAreNotAListAreMalformed()

	/**
	 * A save that fails is refused as storage-failed.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsRefused(): void {
		$result = $this->service(saves: false)->ingest(portal: $this->portal(), body: $this->chunk());

		$this->assertSame(['ok' => false, 'reason' => 'storage-failed'], $result);
		$this->assertCount(1, $this->saves);
	}//end testAFailedSaveIsRefused()

	/**
	 * An existing recording the store gives no uuid for is saved as a new object.
	 *
	 * @return void
	 */
	public function testAnExistingRecordingWithoutAUuidIsSavedAsNew(): void {
		$existing = ['recordingId' => self::RECORDING, 'portal' => 'open-tilburg', 'pages' => [], 'chunks' => [], 'bytes' => 0, 'durationMs' => 0, '@self' => []];

		$result = $this->service(existing: $existing)->ingest(portal: $this->portal(), body: $this->chunk());

		$this->assertSame(['ok' => true, 'reason' => ''], $result);
		$this->assertNull($this->saves[0][1]);
		$this->assertArrayNotHasKey('@self', $this->saves[0][0]);
	}//end testAnExistingRecordingWithoutAUuidIsSavedAsNew()

	/**
	 * A non-string session id and page are stored empty, and the elapsed time is a non-negative whole number.
	 *
	 * @return void
	 */
	public function testOddFieldsAreStoredSafely(): void {
		$cases = [
			'float' => [2500.9, 2500],
			'negative float' => [-3.5, 0],
			'infinite' => [INF, 0],
			'text' => ['1500', 0],
		];
		foreach ($cases as $name => [$elapsed, $expected]) {
			$this->saves = [];
			$this->service()->ingest(portal: $this->portal(), body: $this->chunk(['sessionId' => 7, 'page' => ['/x'], 'elapsed' => $elapsed]));

			$saved = $this->saves[0][0];
			$this->assertSame('', $saved['sessionId'], $name);
			$this->assertSame([], $saved['pages'], $name);
			$this->assertSame('', $saved['chunks'][0]['page'], $name);
			$this->assertSame($expected, $saved['durationMs'], $name);
		}
	}//end testOddFieldsAreStoredSafely()

	/**
	 * The service over a store double.
	 *
	 * @param bool                      $saves    What save() answers.
	 * @param array<string, mixed>|null $existing What find() answers.
	 *
	 * @return TrafficRecordingService
	 */
	private function service(bool $saves = true, ?array $existing = null): TrafficRecordingService {
		$store = $this->createMock(TrafficRecordingStore::class);
		$store->method('find')->willReturn($existing);
		$store->method('uuidOf')->willReturnCallback(static fn (array $row): string => (string)($row['@self']['uuid'] ?? ''));
		$store->method('save')->willReturnCallback(
			function (array $recording, ?string $uuid) use ($saves): bool {
				$this->saves[] = [$recording, $uuid];
				return $saves;
			}
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-05T10:00:00Z'));

		return new TrafficRecordingService(new TrafficConfigResolver(), $store, new TrafficRecordingMask(), new TrafficMetrics($this->config->mock($this)), $clock);
	}//end service()

	/**
	 * A portal with session recording on.
	 *
	 * @return array<string, mixed>
	 */
	private function portal(): array {
		return ['slug' => 'open-tilburg', 'traffic' => ['enabled' => true, 'retentionDays' => 10, 'sensitive' => ['sessionRecording' => true]]];
	}//end portal()

	/**
	 * A valid chunk with overrides.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function chunk(array $extra = []): array {
		return $extra + [
			'recording' => self::RECORDING,
			'page' => '/contact',
			'elapsed' => 1500,
			'events' => [['k' => 's', 't' => 0, 'w' => 800, 'h' => 600, 'n' => ['n' => 'body', 'c' => []]]],
		];
	}//end chunk()
}//end class
