<?php

/**
 * Unit tests for the edges of the `/api/v1/` aliases of the API routes.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Middleware\Support;

use OCA\Portaliq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which paths count as already versioned, where the aliases go when no
 * versioned route is declared, and when a route gets no alias because its
 * versioned twin is declared already.
 *
 * @covers \OCA\Portaliq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Portaliq\Middleware\Support\StandardProtocolPaths
 *
 * @spec openspec/specs/api-contract/spec.md
 */
class ApiPathAliasesEdgesTest extends TestCase {

	/**
	 * Paths and whether they are deprecated.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function paths(): array {
		return [
			'bare api root'          => ['/api', false],
			'api root with slash'    => ['/api/', true],
			'upper case version'     => ['/api/V2/things', false],
			'version alone'          => ['/api/v1', false],
			'three digit version'    => ['/api/v100/things', false],
			'four digit version'     => ['/api/v1000/things', true],
			'version deeper down'    => ['/api/a/b/v3/c', false],
			'version-like word'      => ['/api/v1beta/things', true],
			'health'                 => ['/api/health', false],
			'metrics under app root' => ['/index.php/apps/portaliq/api/metrics', false],
			'health with more'       => ['/api/health/deep', true],
			'session callback'       => ['/portal/api/session/oidc/callback', false],
			'no leading slash'       => ['api/things', true],
			'another app'            => ['/apps/other/api/things', false],
		];
	}//end paths()

	/**
	 * Each path is deprecated exactly when it is an unversioned, non-protocol API path.
	 *
	 * @param string $path       The path.
	 * @param bool   $deprecated Whether it is deprecated.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testWhichPathsAreDeprecated(string $path, bool $deprecated): void {
		$this->assertSame(expected: $deprecated, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testWhichPathsAreDeprecated()

	/**
	 * The successor keeps what precedes the app root and replaces only the `/api` segment.
	 *
	 * @return void
	 */
	public function testTheSuccessorKeepsThePrefixAndTheRest(): void {
		$this->assertSame(expected: '/api/v1/', actual: ApiPathAliases::successorOf(path: '/api/'));
		$this->assertSame(expected: '/api/v1/v1000/things', actual: ApiPathAliases::successorOf(path: '/api/v1000/things'));
		$this->assertSame(
			expected: '/nextcloud/index.php/apps/portaliq/api/v1/health/deep',
			actual: ApiPathAliases::successorOf(path: '/nextcloud/index.php/apps/portaliq/api/health/deep')
		);
		$this->assertNull(actual: ApiPathAliases::successorOf(path: '/index.php/apps/portaliq/api/metrics'));
	}//end testTheSuccessorKeepsThePrefixAndTheRest()

	/**
	 * Without a declared `/api/v1/` route the aliases go first, before every declared route.
	 *
	 * @return void
	 */
	public function testWithoutAVersionedRouteTheAliasesComeFirst(): void {
		$routes = ['routes' => [
			['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
			['name' => 'things#index', 'url' => '/api/things', 'verb' => 'GET'],
			['name' => 'things#create', 'url' => '/api/things', 'verb' => 'POST'],
		], 'ocs' => [['name' => 'x#y', 'url' => '/api/ocs', 'verb' => 'GET']]];

		$declared = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(
			expected: [
				'GET /api/v1/things',
				'POST /api/v1/things',
				'GET /',
				'GET /api/things',
				'POST /api/things',
			],
			actual: array_map(static fn (array $route): string => $route['verb'].' '.$route['url'], $declared['routes'])
		);
		$this->assertSame(expected: $routes['ocs'], actual: $declared['ocs']);
	}//end testWithoutAVersionedRouteTheAliasesComeFirst()

	/**
	 * A route whose versioned twin is declared, verb and path, gets no alias;
	 * the same path with another verb, or without a verb (GET), still does.
	 *
	 * @return void
	 */
	public function testADeclaredTwinIsNotAddedTwice(): void {
		$routes = ['routes' => [
			['name' => 'things#indexV1', 'url' => 'api/v1/things', 'verb' => 'get'],
			['name' => 'things#index', 'url' => '/api/things', 'verb' => 'GET'],
			['name' => 'things#create', 'url' => '/api/things', 'verb' => 'POST'],
			['name' => 'parts#index', 'url' => '/api/parts'],
		]];

		$declared = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(
			expected: ['things#indexV1', 'things#create', 'parts#index', 'things#index', 'things#create', 'parts#index'],
			actual: array_column($declared['routes'], 'name')
		);
		$this->assertSame(expected: '/api/v1/things', actual: $declared['routes'][1]['url']);
		$this->assertSame(expected: 'v1', actual: $declared['routes'][1]['postfix']);
		$this->assertArrayNotHasKey(key: 'verb', array: $declared['routes'][2]);
		$this->assertSame(expected: '/api/v1/parts', actual: $declared['routes'][2]['url']);
	}//end testADeclaredTwinIsNotAddedTwice()

	/**
	 * Protocol and already-versioned routes are left without alias, and a
	 * declaration without routes gets an empty list.
	 *
	 * @return void
	 */
	public function testProtocolAndVersionedRoutesGetNoAlias(): void {
		$routes = ['routes' => [
			['name' => 'health#check', 'url' => '/api/health', 'verb' => 'GET'],
			['name' => 'traffic#script', 'url' => '/api/traffic-client.js', 'verb' => 'GET'],
			['name' => 'zgw#zaken', 'url' => '/api/zgw/zaken/v1/zaken', 'verb' => 'GET'],
			['name' => 'nameless'],
		]];

		$this->assertSame(expected: $routes, actual: ApiPathAliases::withVersionedAliases(routes: $routes));
		$this->assertSame(expected: ['routes' => []], actual: ApiPathAliases::withVersionedAliases(routes: []));
	}//end testProtocolAndVersionedRoutesGetNoAlias()

	/**
	 * A single placeholder is the fallback only when its requirement spans segments.
	 *
	 * @return void
	 */
	public function testTheFallbackNeedsARequirementThatSpansSegments(): void {
		$this->assertTrue(condition: ApiPathAliases::isApiFallback(route: ['url' => 'api/{rest}', 'requirements' => ['rest' => '.*']]));
		$this->assertFalse(condition: ApiPathAliases::isApiFallback(route: ['url' => '/api/{rest}', 'requirements' => ['rest' => '[a-z]+']]));
		$this->assertFalse(condition: ApiPathAliases::isApiFallback(route: ['url' => '/api/{rest}', 'requirements' => ['other' => '.+']]));
		$this->assertFalse(condition: ApiPathAliases::isApiFallback(route: ['url' => '/api/x/{rest}', 'requirements' => ['rest' => '.+']]));
		$this->assertFalse(condition: ApiPathAliases::isApiFallback(route: []));

		$withNarrow = ApiPathAliases::withVersionedAliases(
			routes: ['routes' => [['name' => 'a#b', 'url' => '/api/{rest}', 'verb' => 'GET', 'requirements' => ['rest' => '[a-z]+']]]]
		);
		$this->assertSame(expected: ['/api/v1/{rest}', '/api/{rest}'], actual: array_column($withNarrow['routes'], 'url'));
	}//end testTheFallbackNeedsARequirementThatSpansSegments()
}//end class
