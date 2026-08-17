<?php

/**
 * Portaliq Portal Traffic Service Test
 *
 * What the collector accepts, what it refuses, and what it never stores.
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
use OCA\Portaliq\Service\PortalTrafficService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The traffic service.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficServiceTest extends TestCase {

	/**
	 * Objects the writer was asked to create.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	private PortalTrafficService $service;

	/**
	 * Build the service over a writer that records rather than stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->written = [];

		$this->service = new PortalTrafficService(
			$this->recordingWriter(),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()


	/**
	 * A writer that records what it was asked to store.
	 *
	 * @return PortalObjectWriter The double.
	 */
	private function recordingWriter(): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data): array {
				$this->written[] = $data;
				return $data;
			}
		);

		return $writer;
	}//end recordingWriter()


	/**
	 * A service backed by a working in-memory distributed cache.
	 *
	 * A REAL COUNTER, NOT A MOCK THAT RETURNS A NUMBER. The behaviour under
	 * test is that the count accumulates across calls and then bites; a mock
	 * scripted to return the count would be asserting the script.
	 *
	 * @return PortalTrafficService The service.
	 */
	private function rateLimitedService(): PortalTrafficService {
		$store = [];

		$cache = new class($store) implements \OCP\ICache {

			/**
			 * @param array<string, mixed> $store Backing store, by reference.
			 */
			public function __construct(private array &$store) {
			}

			public function get($key) {
				return ($this->store[$key] ?? null);
			}

			public function set($key, $value, $ttl = 0) {
				$this->store[$key] = $value;
				return true;
			}

			public function hasKey($key) {
				return isset($this->store[$key]);
			}

			public function remove($key) {
				unset($this->store[$key]);
				return true;
			}

			public function clear($prefix = '') {
				$this->store = [];
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}

		};

		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		return new PortalTrafficService(
			$this->recordingWriter(),
			$this->createMock(LoggerInterface::class),
			$factory
		);
	}//end rateLimitedService()


	/**
	 * A portal with a `page_view`-only configuration.
	 *
	 * @param array<int, string> $events The permitted events.
	 *
	 * @return array<string, mixed> The portal record.
	 */
	private function portal(array $events = ['page_view']): array {
		return [
			'slug' => 'demo',
			'traffic' => ['enabled' => true, 'events' => $events],
		];
	}//end portal()


	/**
	 * One well-formed event.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'page_view',
				'clientId' => 'c-1',
				'sessionId' => 's-1',
				'sequence' => 0,
				'pageLocation' => 'https://portal.example/begrippen?q=zaak',
			],
			$overrides
		);
	}//end event()


	/**
	 * A portal that has not enabled measurement collects nothing.
	 *
	 * The default has to be silence: measurement on a public government portal
	 * is something an operator decides, not something an absent field decides.
	 *
	 * @return void
	 */
	public function testAPortalWithoutTrafficEnabledStoresNothing(): void {
		$stored = $this->service->record(portal: ['slug' => 'demo'], events: [$this->event()], region: '');

		$this->assertSame(0, $stored);
		$this->assertSame([], $this->written);
	}//end testAPortalWithoutTrafficEnabledStoresNothing()


	/**
	 * A portal permitting only `page_view` gets exactly that.
	 *
	 * The refusal is the assertion: a collector that quietly widens to whatever
	 * a client sends makes the configuration decorative.
	 *
	 * @return void
	 */
	public function testOnlyPermittedEventsAreStored(): void {
		$stored = $this->service->record(
			portal: $this->portal(events: ['page_view']),
			events: [
				$this->event(),
				$this->event(overrides: ['name' => 'search', 'sequence' => 1]),
			],
			region: ''
		);

		$this->assertSame(1, $stored);
		$this->assertCount(1, $this->written);
		$this->assertSame('page_view', $this->written[0]['name']);
	}//end testOnlyPermittedEventsAreStored()


	/**
	 * An enabled portal that names no events collects nothing.
	 *
	 * "Enabled but unconfigured" is the state a half-finished admin screen
	 * leaves behind, and it must not mean "collect everything".
	 *
	 * @return void
	 */
	public function testEnabledWithNoEventListCollectsNothing(): void {
		$stored = $this->service->record(
			portal: ['slug' => 'demo', 'traffic' => ['enabled' => true]],
			events: [$this->event()],
			region: ''
		);

		$this->assertSame(0, $stored);
	}//end testEnabledWithNoEventListCollectsNothing()


	/**
	 * A repeated `(sessionId, sequence)` is refused.
	 *
	 * A client that resets its counter would otherwise put two events at the
	 * same position in one journey, and the journey would read as fact.
	 *
	 * @return void
	 */
	public function testARepeatedSequenceWithinASessionIsRefused(): void {
		$stored = $this->service->record(
			portal: $this->portal(),
			events: [
				$this->event(overrides: ['sequence' => 7]),
				$this->event(overrides: ['sequence' => 7, 'pageTitle' => 'second']),
			],
			region: ''
		);

		$this->assertSame(1, $stored);
		$this->assertCount(1, $this->written);
	}//end testARepeatedSequenceWithinASessionIsRefused()


	/**
	 * The same sequence in a DIFFERENT session is fine.
	 *
	 * Two visitors both start at 0, and refusing the second would silently
	 * drop every concurrent visit but the first.
	 *
	 * @return void
	 */
	public function testTheSameSequenceInAnotherSessionIsKept(): void {
		$stored = $this->service->record(
			portal: $this->portal(),
			events: [
				$this->event(overrides: ['sessionId' => 's-1', 'sequence' => 0]),
				$this->event(overrides: ['sessionId' => 's-2', 'sequence' => 0]),
			],
			region: ''
		);

		$this->assertSame(2, $stored);
	}//end testTheSameSequenceInAnotherSessionIsKept()


	/**
	 * The stored portal is the SERVER's, never the payload's.
	 *
	 * Otherwise any caller could attribute its traffic to a portal it does not
	 * serve, and every number that portal's operator reads would be someone
	 * else's.
	 *
	 * @return void
	 */
	public function testTheClaimedPortalInThePayloadIsIgnored(): void {
		$this->service->record(
			portal: $this->portal(),
			events: [$this->event(overrides: ['portal' => 'someone-elses-portal'])],
			region: ''
		);

		$this->assertSame('demo', $this->written[0]['portal']);
	}//end testTheClaimedPortalInThePayloadIsIgnored()


	/**
	 * The origin is stripped from a reported location.
	 *
	 * A full URL is how one portal's analytics ends up holding another host's
	 * addresses; the path and query are all an aggregate needs.
	 *
	 * @return void
	 */
	public function testAReportedLocationIsReducedToItsPath(): void {
		$this->service->record(portal: $this->portal(), events: [$this->event()], region: '');

		$this->assertSame('/begrippen?q=zaak', $this->written[0]['pageLocation']);
	}//end testAReportedLocationIsReducedToItsPath()


	/**
	 * `params` is bounded, in count and in type.
	 *
	 * An unbounded map is how personal data reaches an analytics store by
	 * accident — a client that puts a form's contents there should lose them
	 * here rather than have them kept because nothing said otherwise.
	 *
	 * @return void
	 */
	public function testParamsAreBounded(): void {
		$params = ['nested' => ['not' => 'scalar']];
		for ($i = 0; $i < 40; $i++) {
			$params['k' . $i] = str_repeat('x', 400);
		}

		$this->service->record(
			portal: $this->portal(),
			events: [$this->event(overrides: ['params' => $params])],
			region: ''
		);

		$stored = $this->written[0]['params'];
		$this->assertLessThanOrEqual(20, count($stored));
		$this->assertArrayNotHasKey('nested', $stored, 'a non-scalar value must be dropped');
		foreach ($stored as $value) {
			$this->assertLessThanOrEqual(256, strlen($value));
		}
	}//end testParamsAreBounded()


	/**
	 * NO STORED FIELD CONTAINS THE REQUEST ADDRESS.
	 *
	 * The service is handed a region and never an address, so this asserts the
	 * property the design rests on rather than an implementation detail: an
	 * address passed as the region would still be caught, because the whole
	 * stored record is searched for it.
	 *
	 * @return void
	 */
	public function testNoStoredFieldEverContainsAnIpAddress(): void {
		$address = '203.0.113.42';
		$region = $this->service->regionFor(address: $address, portal: $this->portal());

		$this->service->record(portal: $this->portal(), events: [$this->event()], region: $region);

		$encoded = json_encode($this->written);
		$this->assertStringNotContainsString($address, (string)$encoded);
		$this->assertStringNotContainsString('203.0.113', (string)$encoded);
	}//end testNoStoredFieldEverContainsAnIpAddress()


	/**
	 * One client id cannot fill a portal's analytics on its own.
	 *
	 * A LOOPING CLIENT IS THE THREAT MODEL, not an attacker — a client id is
	 * chosen by the client, so anything determined simply rotates it, and the
	 * control that bounds an abusive SOURCE is the controller's
	 * `#[AnonRateLimit]`. What this asserts is that a beacon stuck in a retry
	 * loop stops being written before it becomes the portal's traffic.
	 *
	 * @return void
	 */
	public function testOneClientIdIsRateLimitedWithinAWindow(): void {
		$service = $this->rateLimitedService();

		$events = [];
		for ($i = 0; $i < 320; $i++) {
			$events[] = $this->event(overrides: ['sequence' => $i]);
		}

		// Sent as batches the controller would accept, because the limit is
		// per WINDOW and must hold across separate requests rather than only
		// within one.
		$stored = 0;
		foreach (array_chunk($events, 40) as $batch) {
			$stored += $service->record(portal: $this->portal(), events: $batch, region: '');
		}

		$this->assertSame(300, $stored, 'the limit did not bite at the configured ceiling');
		$this->assertSame(20, ($service->refusals()['rate_limited'] ?? 0));
	}//end testOneClientIdIsRateLimitedWithinAWindow()


	/**
	 * A SECOND client id is not punished for the first one's behaviour.
	 *
	 * The failure this guards against is a limiter keyed on nothing in
	 * particular, which reads as "the limit works" on the first test and
	 * silently stops measuring every other visitor on a busy portal.
	 *
	 * @return void
	 */
	public function testAnotherClientIdIsUnaffectedByTheFirstsLimit(): void {
		$service = $this->rateLimitedService();

		$flood = [];
		for ($i = 0; $i < 320; $i++) {
			$flood[] = $this->event(overrides: ['sequence' => $i]);
		}
		foreach (array_chunk($flood, 40) as $batch) {
			$service->record(portal: $this->portal(), events: $batch, region: '');
		}

		$stored = $service->record(
			portal: $this->portal(),
			events: [$this->event(overrides: ['clientId' => 'c-2', 'sessionId' => 's-2'])],
			region: ''
		);

		$this->assertSame(1, $stored, 'an unrelated visitor was throttled by another visitor');
	}//end testAnotherClientIdIsUnaffectedByTheFirstsLimit()


	/**
	 * With no cache available the collector still measures.
	 *
	 * FAILING OPEN IS THE DECISION HERE and it is asserted rather than assumed:
	 * an instance with no memcache configured must not silently stop recording
	 * traffic, because that loss would present as "nobody visits this portal".
	 *
	 * @return void
	 */
	public function testWithoutACacheTheCollectorStillRecords(): void {
		$stored = $this->service->record(
			portal: $this->portal(),
			events: [$this->event()],
			region: ''
		);

		$this->assertSame(1, $stored);
		$this->assertArrayNotHasKey('rate_limited', $this->service->refusals());
	}//end testWithoutACacheTheCollectorStillRecords()


	/**
	 * A refusal is counted under the reason it actually had.
	 *
	 * "One fewer was stored" is true of every failure mode at once. Asserting
	 * the REASON is what makes a refusal visible to an operator as the thing it
	 * was, and what stops a future change from silently reclassifying it.
	 *
	 * @return void
	 */
	public function testEachRefusalIsCountedUnderItsOwnReason(): void {
		$this->service->record(
			portal: $this->portal(events: ['page_view']),
			events: [
				$this->event(overrides: ['name' => 'search', 'sequence' => 1]),
				$this->event(overrides: ['clientId' => '', 'sequence' => 2]),
				$this->event(overrides: ['sequence' => 3]),
				$this->event(overrides: ['sequence' => 3]),
			],
			region: ''
		);

		$this->assertSame(
			['event_not_permitted' => 1, 'incomplete' => 1, 'duplicate_sequence' => 1],
			$this->service->refusals()
		);
	}//end testEachRefusalIsCountedUnderItsOwnReason()


	/**
	 * A portal asking for no region gets none.
	 *
	 * @return void
	 */
	public function testRegionGranularityNoneYieldsNoRegion(): void {
		$portal = ['slug' => 'demo', 'traffic' => ['enabled' => true, 'regionGranularity' => 'none']];

		$this->assertSame('', $this->service->regionFor(address: '203.0.113.42', portal: $portal));
	}//end testRegionGranularityNoneYieldsNoRegion()


}//end class
