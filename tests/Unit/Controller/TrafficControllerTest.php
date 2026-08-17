<?php

/**
 * Portaliq Traffic Controller Test
 *
 * That the collector is reachable by an anonymous caller, and that being
 * reachable is not the same as collecting.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\TrafficController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalTrafficService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The traffic collector's HTTP surface.
 *
 * THE POINT OF THIS FILE IS THE PAIR OF ASSERTIONS, not either one alone. That
 * an anonymous request is ACCEPTED proves the endpoint is genuinely public —
 * a guard that has only ever been reached by an authenticated test proves
 * nothing about the visitor it exists for. That a DISABLED portal stores
 * nothing proves the refusal path can actually fire. Either half on its own is
 * satisfied by a broken collector: one by a collector that accepts everything,
 * the other by a collector that accepts nothing.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class TrafficControllerTest extends TestCase {

	/**
	 * Events the service was asked to record, by call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $recorded = [];

	/**
	 * Refusal reasons counted.
	 *
	 * @var array<int, string>
	 */
	private array $refusals = [];


	/**
	 * A controller over a resolver that answers with the given portal.
	 *
	 * @param array<string, mixed>|null $portal The portal the request resolves to.
	 *
	 * @return TrafficController The controller.
	 */
	private function controller(?array $portal): TrafficController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.42');

		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);

		$traffic = $this->createMock(PortalTrafficService::class);
		$traffic->method('regionFor')->willReturn('');
		$traffic->method('record')->willReturnCallback(
			function (array $portal, array $events, string $region): int {
				$this->recorded[] = $events;
				return count($events);
			}
		);
		$traffic->method('countRefusal')->willReturnCallback(
			function (string $reason): void {
				$this->refusals[] = $reason;
			}
		);

		return new TrafficController(
			appName: 'portaliq',
			request: $request,
			resolver: $resolver,
			traffic: $traffic
		);
	}//end controller()


	/**
	 * One well-formed event.
	 *
	 * @return array<int, array<string, mixed>> The batch.
	 */
	private function batch(): array {
		return [
			[
				'name' => 'page_view',
				'clientId' => 'c-1',
				'sessionId' => 's-1',
				'sequence' => 0,
				'pageLocation' => 'https://portal.example/begrippen',
			],
		];
	}//end batch()


	/**
	 * Reset the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->recorded = [];
		$this->refusals = [];
	}//end setUp()


	/**
	 * The endpoint declares the attributes that make it anonymous.
	 *
	 * Declared rather than merely working: `#[PublicPage]` is what admits a
	 * caller with no session and `#[NoCSRFRequired]` is what admits a beacon,
	 * and losing either one turns every visitor's report into a silent 401 that
	 * a `sendBeacon` cannot report to anyone.
	 *
	 * @return void
	 */
	public function testTheCollectorIsDeclaredPublicAndBeaconCompatible(): void {
		$method = (new ReflectionClass(TrafficController::class))->getMethod('collect');

		$this->assertNotEmpty($method->getAttributes(PublicPage::class));
		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));

		$limit = $method->getAttributes(AnonRateLimit::class);
		$this->assertNotEmpty($limit, 'a public collector with no rate limit is an open write endpoint');
		$this->assertGreaterThan(0, ($limit[0]->newInstance()->getLimit()));
	}//end testTheCollectorIsDeclaredPublicAndBeaconCompatible()


	/**
	 * A real anonymous request is accepted and recorded.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestIsAcceptedAndRecorded(): void {
		$response = $this->controller(portal: ['slug' => 'demo'])->collect(events: $this->batch());

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertCount(1, $this->recorded, 'an anonymous visitor was not measured at all');
	}//end testAnAnonymousRequestIsAcceptedAndRecorded()


	/**
	 * A request that resolves to no portal records nothing.
	 *
	 * @return void
	 */
	public function testARequestForNoPortalRecordsNothing(): void {
		$response = $this->controller(portal: null)->collect(events: $this->batch());

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([], $this->recorded);
	}//end testARequestForNoPortalRecordsNothing()


	/**
	 * An oversized batch is refused WHOLE, and the refusal is counted.
	 *
	 * @return void
	 */
	public function testAnOversizedBatchIsRefusedWholeAndCounted(): void {
		$events = array_fill(0, 51, $this->batch()[0]);

		$this->controller(portal: ['slug' => 'demo'])->collect(events: $events);

		$this->assertSame([], $this->recorded, 'part of an oversized batch was stored');
		$this->assertSame(['batch'], $this->refusals);
	}//end testAnOversizedBatchIsRefusedWholeAndCounted()


	/**
	 * An empty or malformed batch is refused and counted.
	 *
	 * @return void
	 */
	public function testAnEmptyBatchIsRefusedAndCounted(): void {
		$this->controller(portal: ['slug' => 'demo'])->collect(events: []);

		$this->assertSame([], $this->recorded);
		$this->assertSame(['batch'], $this->refusals);
	}//end testAnEmptyBatchIsRefusedAndCounted()


	/**
	 * THE RESPONSE NEVER TELLS THE CALLER ANYTHING.
	 *
	 * Accepted, refused, unknown portal — all 204 with no body. A beacon fired
	 * during unload cannot read a response anyway, so a distinguishable one
	 * only serves a caller probing which portals exist.
	 *
	 * @return void
	 */
	public function testEveryOutcomeLooksIdenticalToTheCaller(): void {
		$accepted = $this->controller(portal: ['slug' => 'demo'])->collect(events: $this->batch());
		$refused = $this->controller(portal: ['slug' => 'demo'])->collect(events: []);
		$unknown = $this->controller(portal: null)->collect(events: $this->batch());

		foreach ([$accepted, $refused, $unknown] as $response) {
			$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
			$this->assertSame([], $response->getData());
		}
	}//end testEveryOutcomeLooksIdenticalToTheCaller()


}//end class
