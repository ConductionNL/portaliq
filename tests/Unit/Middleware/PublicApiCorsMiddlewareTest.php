<?php

/**
 * Unit tests for PublicApiCorsMiddleware.
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

namespace OCA\Portaliq\Tests\Unit\Middleware;

use OCA\Portaliq\Middleware\PublicApiCorsMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Reflect-Origin on the two public path families, nowhere else, and never
 * with credentials.
 */
class PublicApiCorsMiddlewareTest extends TestCase {

	/**
	 * Run the middleware over a response for a request.
	 *
	 * @param string $origin   The Origin header.
	 * @param string $path     The request path info.
	 * @param bool   $isPublic Whether the dispatched method is #[PublicPage].
	 *
	 * @return array<string, string> The response headers.
	 */
	private function headersFor(string $origin, string $path, bool $isPublic = true): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin') ? $origin : ''
		);
		$request->method('getPathInfo')->willReturn($path);

		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotationOrAttribute')->willReturn($isPublic);

		$middleware = new PublicApiCorsMiddleware($request, $reflector);
		$response = $middleware->afterController($this->createMock(Controller::class), 'anything', new Response());

		// Read off the object: `getHeaders()` merges platform defaults and
		// needs the Nextcloud runtime for the request id.
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersFor()


	/**
	 * A public content or traffic response reflects the origin, allows GET
	 * and POST, varies on Origin, and NEVER allows credentials.
	 *
	 * @return void
	 */
	public function testAPublicTrafficOrContentResponseReflectsTheOriginWithoutCredentials(): void {
		foreach (['/api/traffic', '/api/traffic/pixel.gif', '/api/traffic-client.js', '/api/content/site', '/api/content'] as $path) {
			$headers = $this->headersFor(origin: 'https://docs.example', path: $path);

			$this->assertSame('https://docs.example', $headers['Access-Control-Allow-Origin'] ?? null, $path);
			$this->assertSame('GET, POST', $headers['Access-Control-Allow-Methods'] ?? null, $path);
			$this->assertSame('Origin', $headers['Vary'] ?? null, $path);
			$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers, $path);
		}
	}//end testAPublicTrafficOrContentResponseReflectsTheOriginWithoutCredentials()


	/**
	 * Any other path in this app, public or not, gets no CORS header at
	 * all: the reflection is scoped to the two families the contract names.
	 *
	 * @return void
	 */
	public function testOtherPathsAreNeverOpened(): void {
		foreach (['/api/settings', '/api/metrics', '/api/trafficking', '/portal/api/session', '/api/contentious'] as $path) {
			$headers = $this->headersFor(origin: 'https://docs.example', path: $path);

			$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers, $path);
		}
	}//end testOtherPathsAreNeverOpened()


	/**
	 * A non-public method under a public path family is not opened either.
	 *
	 * @return void
	 */
	public function testANonPublicMethodIsNotOpened(): void {
		$headers = $this->headersFor(origin: 'https://docs.example', path: '/api/content/site', isPublic: false);

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
	}//end testANonPublicMethodIsNotOpened()


	/**
	 * A missing or malformed Origin adds nothing.
	 *
	 * @return void
	 */
	public function testAMissingOrMalformedOriginAddsNothing(): void {
		foreach (['', 'null', 'docs.example', 'https://docs.example/path', 'javascript:alert(1)'] as $origin) {
			$headers = $this->headersFor(origin: $origin, path: '/api/traffic');

			$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers, 'origin: ' . $origin);
		}
	}//end testAMissingOrMalformedOriginAddsNothing()
}//end class
