<?php

/**
 * Unit tests for TrafficPathController.
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

use OCA\Portaliq\Controller\TrafficPathController;
use OCA\Portaliq\Service\Traffic\TrafficPathService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The paths endpoint hands a good request to the service, refuses a bad
 * one with a reason, and stays admin-only.
 */
class TrafficPathControllerTest extends TestCase {

	/**
	 * The arguments the service was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];


	/**
	 * The controller over a service that echoes its arguments, or answers
	 * the given error.
	 *
	 * @param string|null $error The error the service answers, or null.
	 *
	 * @return TrafficPathController The controller.
	 */
	private function controller(?string $error = null): TrafficPathController {
		$service = $this->createMock(TrafficPathService::class);
		$service->method('explore')->willReturnCallback(
			function (string $portal, string $from, string $to, string $segment, string $mode, string $anchor, int $steps, array $trail) use ($error): array {
				$this->calls[] = compact('portal', 'from', 'to', 'segment', 'mode', 'anchor', 'steps', 'trail');
				if ($error !== null) {
					return ['error' => $error];
				}

				return ['portal' => $portal, 'sessions' => 4, 'columns' => [], 'links' => []];
			}
		);

		return new TrafficPathController('portaliq', $this->createMock(IRequest::class), $service);
	}//end controller()


	/**
	 * The headers a response was given.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()


	/**
	 * A good request reaches the service with the defaults filled in and
	 * the trail parsed, and the answer is not cached.
	 *
	 * @return void
	 */
	public function testAGoodRequestReachesTheServiceAndIsNotCached(): void {
		$controller = $this->controller();

		$response = $controller->paths(portal: 'open-tilburg', from: '2026-09-01', to: '2026-09-23');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(4, $response->getData()['sessions']);
		$this->assertSame('private, no-store', $this->headers($response)['Cache-Control']);
		$this->assertSame(
			['portal' => 'open-tilburg', 'from' => '2026-09-01', 'to' => '2026-09-23', 'segment' => '', 'mode' => 'start', 'anchor' => '', 'steps' => 3, 'trail' => []],
			$this->calls[0]
		);

		$controller->paths(
			portal: 'open-tilburg',
			from: '2026-09-01',
			to: '2026-09-23',
			segment: 'mobile',
			mode: 'end',
			anchor: '/contact',
			steps: '10',
			trail: '[null, "/news", ""]'
		);
		$this->assertSame(['mode' => 'end', 'anchor' => '/contact', 'steps' => 10, 'trail' => [null, '/news', '']], array_intersect_key($this->calls[1], ['mode' => 0, 'anchor' => 0, 'steps' => 0, 'trail' => 0]));
	}//end testAGoodRequestReachesTheServiceAndIsNotCached()


	/**
	 * Scenario: a malformed request is refused with a reason, and the
	 * service is never asked.
	 *
	 * @return void
	 */
	public function testAMalformedRequestIsRefusedWithAReason(): void {
		$good = ['portal' => 'open-tilburg', 'from' => '2026-09-01', 'to' => '2026-09-23'];
		$cases = [
			'missing-portal' => ['portal' => 'bad slug!'],
			'invalid-range' => ['from' => '2026-09-24'],
			'range-too-long' => ['from' => '2025-01-01'],
			'invalid-segment' => ['segment' => 'no spaces'],
			'invalid-mode' => ['mode' => 'sideways'],
			'invalid-anchor' => ['anchor' => 'contact'],
			'invalid-steps' => ['steps' => '12'],
			'invalid-trail' => ['trail' => '["/a", 3]'],
		];
		foreach ($cases as $reason => $override) {
			$response = $this->controller()->paths(...array_merge($good, $override));
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $reason);
			$this->assertSame(['error' => $reason], $response->getData(), $reason);
			$this->assertSame('private, no-store', $this->headers($response)['Cache-Control'], $reason);
		}

		foreach (['0', 'x', '-1'] as $steps) {
			$this->assertSame(['error' => 'invalid-steps'], $this->controller()->paths(...array_merge($good, ['steps' => $steps]))->getData(), $steps);
		}

		foreach (['{"a": "/x"}', 'not json', '["/a", "/b", "/c", "/d", "/e"]', '["/a\nb"]'] as $trail) {
			$this->assertSame(['error' => 'invalid-trail'], $this->controller()->paths(...array_merge($good, ['trail' => $trail]))->getData(), $trail);
		}

		$this->assertSame([], $this->calls);
	}//end testAMalformedRequestIsRefusedWithAReason()


	/**
	 * An unknown portal is a 404; a segment the portal lacks is a 400.
	 *
	 * @return void
	 */
	public function testTheServicesRefusalsKeepTheirReason(): void {
		$missing = $this->controller(error: 'unknown-portal')->paths(portal: 'open-breda', from: '2026-09-01', to: '2026-09-23');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'unknown-portal'], $missing->getData());

		$segment = $this->controller(error: 'unknown-segment')->paths(portal: 'open-tilburg', from: '2026-09-01', to: '2026-09-23', segment: 'gone');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $segment->getStatus());
		$this->assertSame(['error' => 'unknown-segment'], $segment->getData());
	}//end testTheServicesRefusalsKeepTheirReason()


	/**
	 * Admin-only by omission: no public or no-admin attribute, and the
	 * posture is declared where the route-auth gate reads it.
	 *
	 * @return void
	 */
	public function testThePathsStayAdminOnly(): void {
		$method = (new ReflectionClass(TrafficPathController::class))->getMethod('paths');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertMatchesRegularExpression('/@auth admin-only .{20,}/', (string)$method->getDocComment());
	}//end testThePathsStayAdminOnly()
}//end class
