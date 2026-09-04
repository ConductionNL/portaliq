<?php

/**
 * Unit tests for TrafficController.
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\TrafficController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\RawRequestBody;
use OCA\Portaliq\Service\TrafficIngestService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The collector as an HTTP surface: what it refuses whole, what it
 * answers, and what it never sets.
 *
 * The ingest service is a double here. Its own tests cover what reaches
 * storage; these cover the envelope around it, which is the part a browser
 * on another origin sees.
 */
class TrafficControllerTest extends TestCase {

	/**
	 * The batches the ingest double received, as [slug, events, context].
	 *
	 * @var array<int, array{0: ?string, 1: array, 2: array}>
	 */
	private array $ingested = [];

	/**
	 * A temporary app directory holding a built client, or not.
	 *
	 * @var string
	 */
	private string $appDir = '';


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ingested = [];
		$this->appDir = sys_get_temp_dir() . '/portaliq-traffic-' . bin2hex(random_bytes(4));
		mkdir($this->appDir . '/js', 0777, true);
	}//end setUp()


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		if (is_file($this->appDir . '/js/portaliq-traffic.js') === true) {
			unlink($this->appDir . '/js/portaliq-traffic.js');
		}

		if (is_dir($this->appDir . '/js') === true) {
			rmdir($this->appDir . '/js');
			rmdir($this->appDir);
		}

		parent::tearDown();
	}//end tearDown()


	/**
	 * A controller over a canned body, a portal that resolves (or not), and
	 * a capturing ingest double.
	 *
	 * @param string|null          $body    What the request body reads as; null means over the cap.
	 * @param array|null           $portal  What the host resolves to.
	 * @param array<string,string> $headers Request headers.
	 *
	 * @return TrafficController The controller.
	 */
	private function controller(?string $body, ?array $portal = ['slug' => 'open-tilburg'], array $headers = []): TrafficController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.9');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (string)($headers[$name] ?? '')
		);

		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolveForCollector')->willReturn($portal);

		$ingest = $this->createMock(TrafficIngestService::class);
		$ingest->method('ingestForPortal')->willReturnCallback(
			function (array $portal, array $events, array $context): array {
				$this->ingested[] = [$portal['slug'], $events, $context];

				return ['accepted' => count($events), 'refused' => []];
			}
		);
		$ingest->method('ingest')->willReturnCallback(
			function (string $portalSlug, array $events, array $context): array {
				$this->ingested[] = [$portalSlug, $events, $context];

				return ['accepted' => 0, 'refused' => ['unknown-portal' => count($events)]];
			}
		);

		$raw = $this->createMock(RawRequestBody::class);
		$raw->method('read')->willReturn($body);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);

		return new TrafficController('portaliq', $request, $resolver, $ingest, $raw, $appManager);
	}//end controller()


	/**
	 * The headers a response was GIVEN, read off the object rather than
	 * through `getHeaders()`, which merges in platform defaults and needs
	 * the Nextcloud runtime for the request id.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()


	/**
	 * A batch of `$count` page views, as JSON.
	 *
	 * @param int $count How many events.
	 *
	 * @return string The body.
	 */
	private function batch(int $count): string {
		$events = [];
		for ($i = 0; $i < $count; $i++) {
			$events[] = ['name' => 'page_view', 'sequence' => $i, 'pageLocation' => 'https://open-tilburg.nl/p' . $i];
		}

		return (string)json_encode(['portal' => 'open-tilburg', 'consent' => true, 'events' => $events]);
	}//end batch()


	/**
	 * A valid batch is 204 with no body, no cookie and a private no-store
	 * cache header, and every event reached the ingest service with the
	 * request's address and agent in the CONTEXT, not in the events.
	 *
	 * @return void
	 */
	public function testAValidBatchIsAcceptedWithNoBodyAndNoCookie(): void {
		$controller = $this->controller(body: $this->batch(3), headers: ['User-Agent' => 'Mozilla/5.0 test']);

		$response = $controller->collect();

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame('', (string)$response->render());
		$this->assertSame('private, no-store', $this->headers($response)['Cache-Control']);
		$this->assertSame([], $response->getCookies());

		$this->assertCount(1, $this->ingested);
		[$slug, $events, $context] = $this->ingested[0];
		$this->assertSame('open-tilburg', $slug);
		$this->assertCount(3, $events);
		$this->assertSame('203.0.113.9', $context['ip']);
		$this->assertSame('Mozilla/5.0 test', $context['userAgent']);
		$this->assertTrue($context['consent']);
		$this->assertFalse($context['serverSide'], 'an HTTP batch is never server-side');
	}//end testAValidBatchIsAcceptedWithNoBodyAndNoCookie()


	/**
	 * Malformed JSON, a body with no events, and an events value that is
	 * not a list are each refused WHOLE with a 400, and nothing is ingested.
	 *
	 * @return void
	 */
	public function testAMalformedBatchIsRefusedWholeWithNothingStored(): void {
		foreach (['{not json', '{"events": []}', '{"events": "page_view"}', '[]', ''] as $body) {
			$this->ingested = [];
			$response = $this->controller(body: $body)->collect();

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), 'body: ' . $body);
			$this->assertSame(['error' => 'malformed-batch'], $response->getData(), 'body: ' . $body);
			$this->assertSame([], $this->ingested, 'nothing may reach the ingest service for: ' . $body);
		}
	}//end testAMalformedBatchIsRefusedWholeWithNothingStored()


	/**
	 * Fifty events is the most a batch may carry; fifty-one is refused
	 * whole, and so is a body over the byte cap (which the body reader
	 * reports as null, without buffering it).
	 *
	 * @return void
	 */
	public function testAnOversizedBatchIsRefusedWhole(): void {
		$fifty = $this->controller(body: $this->batch(50))->collect();
		$this->assertSame(Http::STATUS_NO_CONTENT, $fifty->getStatus(), 'fifty is the cap, not over it');
		$this->assertCount(1, $this->ingested);

		$this->ingested = [];
		$fiftyOne = $this->controller(body: $this->batch(51))->collect();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $fiftyOne->getStatus());
		$this->assertSame(['error' => 'batch-too-large'], $fiftyOne->getData());
		$this->assertSame([], $this->ingested);

		$overCap = $this->controller(body: null)->collect();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $overCap->getStatus());
		$this->assertSame(['error' => 'batch-too-large'], $overCap->getData());
		$this->assertSame([], $this->ingested);
	}//end testAnOversizedBatchIsRefusedWhole()


	/**
	 * When the host resolves to nothing, the batch still reaches the ingest
	 * service by slug, which refuses and COUNTS it as `unknown-portal`
	 * rather than dropping it silently; the client still gets its 204,
	 * because a collector must not be a portal-existence oracle.
	 *
	 * @return void
	 */
	public function testAnUnresolvedPortalIsCountedNotRevealed(): void {
		$response = $this->controller(body: $this->batch(2), portal: null)->collect();

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertCount(1, $this->ingested);
		$this->assertSame('open-tilburg', $this->ingested[0][0], 'the named slug is handed on for the refusal count');
	}//end testAnUnresolvedPortalIsCountedNotRevealed()


	/**
	 * A batch that says nothing about consent is treated as WITHOUT it.
	 *
	 * @return void
	 */
	public function testConsentDefaultsToFalse(): void {
		$body = (string)json_encode(['events' => [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x.example/']]]);
		$this->controller(body: $body)->collect();

		$this->assertFalse($this->ingested[0][2]['consent']);
	}//end testConsentDefaultsToFalse()


	/**
	 * The pixel answers a GIF whatever happened, ingests one event whose
	 * location falls back to the Referer, and is never cacheable.
	 *
	 * @return void
	 */
	public function testThePixelIngestsOneEventAndAnswersAGif(): void {
		$controller = $this->controller(body: '', headers: ['Referer' => 'https://open-tilburg.nl/woo']);

		$response = $controller->pixel(portal: 'open-tilburg', e: null, l: null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/gif', $this->headers($response)['Content-Type']);
		$this->assertSame('private, no-store', $this->headers($response)['Cache-Control']);
		$this->assertStringStartsWith('GIF89a', (string)$response->render());

		$this->assertCount(1, $this->ingested);
		[, $events] = $this->ingested[0];
		$this->assertSame('page_view', $events[0]['name']);
		$this->assertSame('https://open-tilburg.nl/woo', $events[0]['pageLocation']);
	}//end testThePixelIngestsOneEventAndAnswersAGif()


	/**
	 * The built client is served as JavaScript, cacheable for an hour, with
	 * an ETag that answers a 304 on a match.
	 *
	 * @return void
	 */
	public function testTheClientIsServedWithAnEtagAndAnswers304OnAMatch(): void {
		file_put_contents($this->appDir . '/js/portaliq-traffic.js', '(function(){})();');

		$response = $this->controller(body: '')->client();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('application/javascript; charset=utf-8', $this->headers($response)['Content-Type']);
		$this->assertSame('public, max-age=3600', $this->headers($response)['Cache-Control']);
		$this->assertSame('(function(){})();', (string)$response->render());
		$etag = $this->headers($response)['ETag'];
		$this->assertMatchesRegularExpression('/^"\d+-17"$/', $etag);

		$again = $this->controller(body: '', headers: ['If-None-Match' => $etag])->client();
		$this->assertSame(Http::STATUS_NOT_MODIFIED, $again->getStatus());
		$this->assertSame('', (string)$again->render());
	}//end testTheClientIsServedWithAnEtagAndAnswers304OnAMatch()


	/**
	 * A client that was never built is a 404, not a 200 with an empty body
	 * that a browser would cache for an hour.
	 *
	 * @return void
	 */
	public function testAMissingClientIsNotFoundAndNotCached(): void {
		$response = $this->controller(body: '')->client();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('private, no-store', $this->headers($response)['Cache-Control']);
	}//end testAMissingClientIsNotFoundAndNotCached()


	/**
	 * Every routed method is public, CSRF-exempt and rate-limited for BOTH
	 * anonymous and authenticated callers. The user limit is not optional:
	 * without it an editor previewing their own site is throttled at
	 * Nextcloud's default, far below a visitor.
	 *
	 * @return void
	 */
	public function testEveryRoutedMethodCarriesTheFullPublicPosture(): void {
		$reflection = new ReflectionClass(TrafficController::class);
		foreach (['collect', 'pixel', 'client'] as $name) {
			$method = $reflection->getMethod($name);
			foreach ([PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class, UserRateLimit::class] as $attribute) {
				$this->assertNotEmpty($method->getAttributes($attribute), $name . '() must carry #[' . $attribute . ']');
			}

			/** @var AnonRateLimit $anon */
			$anon = $method->getAttributes(AnonRateLimit::class)[0]->newInstance();
			/** @var UserRateLimit $user */
			$user = $method->getAttributes(UserRateLimit::class)[0]->newInstance();
			$this->assertGreaterThan(0, $anon->getLimit());
			$this->assertGreaterThanOrEqual($anon->getLimit(), $user->getLimit(), $name . '(): a signed-in caller must not be throttled below a visitor');
		}
	}//end testEveryRoutedMethodCarriesTheFullPublicPosture()
}//end class
