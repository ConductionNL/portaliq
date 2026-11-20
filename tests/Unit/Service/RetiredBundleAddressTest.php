<?php

/**
 * Tests for RetiredBundleAddress: every path the WOO bundle answered resolves on
 * the authored site.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\RetiredBundleAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The enumerated route record of the bundle against the mapping.
 *
 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
 */
#[CoversClass(RetiredBundleAddress::class)]
final class RetiredBundleAddressTest extends TestCase {

	/**
	 * The route record of the bundle, wherever the change sits (open or archived).
	 *
	 * @return array{site: string, routes: list<array<string, string>>}
	 */
	private static function record(): array {
		$root = dirname(__DIR__, 3) . '/openspec/changes';
		$files = array_merge(
			glob($root . '/retire-the-vendored-woo-bundle/bundle-routes.json') ?: [],
			glob($root . '/archive/*-retire-the-vendored-woo-bundle/bundle-routes.json') ?: []
		);
		self::assertNotSame([], $files, 'bundle-routes.json is missing');

		return json_decode((string)file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
	}//end record()

	/**
	 * Every route the bundle answered, with a concrete path for its pattern.
	 *
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function bundleRoutes(): array {
		$cases = [];
		foreach (self::record()['routes'] as $route) {
			$path = str_replace([':id', ':type'], ['0f3c9a52-1b7e-4c11-9d1a-3e5f2b7c8a90', 'applicaties'], $route['path']);
			if ($path === '*') {
				$path = '/een-pad-dat-de-bundel-niet-kende';
			}

			$cases[$route['path']] = [$path, $route];
		}

		return $cases;
	}//end bundleRoutes()

	/**
	 * The record is the whole set the bundle answers, not a sample: 47 routes.
	 *
	 * @return void
	 */
	public function testTheRecordIsTheEnumeratedSet(): void {
		self::assertCount(47, self::record()['routes']);
	}//end testTheRecordIsTheEnumeratedSet()

	/**
	 * Each route resolves where its record says its surface now lives.
	 *
	 * @param string                $path  A concrete path of the route.
	 * @param array<string, string> $route The record of the route.
	 *
	 * @return void
	 */
	#[DataProvider('bundleRoutes')]
	public function testEveryBundleRouteResolvesWhereItsSurfaceLives(string $path, array $route): void {
		$resolved = (new RetiredBundleAddress())->siteRoute(path: $path);

		$expected = match ($route['kind']) {
			'auth', 'own-area' => RetiredBundleAddress::OWN_AREA,
			'out-of-scope' => RetiredBundleAddress::HOME,
			default => rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
		};
		self::assertSame($expected, $resolved, $route['path']);

		if (isset($route['to']) === true && $route['to'] !== '*') {
			self::assertStringStartsWith($route['to'], $resolved, $route['path']);
		}
	}//end testEveryBundleRouteResolvesWhereItsSurfaceLives()

	/**
	 * Each page a public route lands on is a page of the authored site.
	 *
	 * @return void
	 */
	public function testEveryPageTargetIsAPageOfTheSite(): void {
		$record = self::record();
		$site = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/sites/' . $record['site'] . '.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$pages = array_column($site['pages'], 'route');

		foreach ($record['routes'] as $route) {
			if ($route['kind'] === 'page' && $route['to'] !== '*') {
				self::assertContains($route['to'], $pages, $route['path']);
			}
		}
	}//end testEveryPageTargetIsAPageOfTheSite()

	/**
	 * A route sharing only a prefix with a retired one keeps its own route.
	 *
	 * @return void
	 */
	public function testARouteThatOnlySharesAPrefixKeepsItsRoute(): void {
		$address = new RetiredBundleAddress();

		self::assertSame('/formsx', $address->siteRoute(path: 'formsx'));
		self::assertSame('/loginhulp', $address->siteRoute(path: '/loginhulp'));
	}//end testARouteThatOnlySharesAPrefixKeepsItsRoute()

	/**
	 * Empty and dot segments are dropped, so a path cannot climb out of the site.
	 *
	 * @return void
	 */
	public function testDotAndEmptySegmentsAreDropped(): void {
		$address = new RetiredBundleAddress();

		self::assertSame('/', $address->siteRoute(path: ''));
		self::assertSame('/zoeken', $address->siteRoute(path: '//zoeken/'));
		self::assertSame('/etc/passwd', $address->siteRoute(path: '../../etc/passwd'));
		self::assertSame('/publicatie/abc', $address->siteRoute(path: 'publicatie\\.\\abc'));
	}//end testDotAndEmptySegmentsAreDropped()
}//end class
