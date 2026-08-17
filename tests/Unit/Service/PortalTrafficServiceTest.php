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

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data): array {
				$this->written[] = $data;
				return $data;
			}
		);

		$this->service = new PortalTrafficService(
			$writer,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()


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
	 * A portal asking for no region gets none.
	 *
	 * @return void
	 */
	public function testRegionGranularityNoneYieldsNoRegion(): void {
		$portal = ['slug' => 'demo', 'traffic' => ['enabled' => true, 'regionGranularity' => 'none']];

		$this->assertSame('', $this->service->regionFor(address: '203.0.113.42', portal: $portal));
	}//end testRegionGranularityNoneYieldsNoRegion()


}//end class
