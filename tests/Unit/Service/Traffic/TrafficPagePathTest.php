<?php

/**
 * Unit tests for TrafficPagePath.
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

use OCA\Portaliq\Service\Traffic\TrafficPagePath;
use PHPUnit\Framework\TestCase;

/**
 * A page view is keyed by its in-site route, the way the traffic client's
 * `siteRoute` keys it.
 */
class TrafficPagePathTest extends TestCase {

	/**
	 * The built-in site's route parameter wins over the renderer's path,
	 * and no route parameter is the home page.
	 *
	 * @return void
	 */
	public function testTheBuiltInSiteIsKeyedByItsRouteParameter(): void {
		$paths = new TrafficPagePath();
		$site = 'https://cloud.example/index.php/apps/portaliq/site';

		$this->assertSame('/contact', $paths->ofEvent(event: ['pagePath' => '/index.php/apps/portaliq/site', 'pageLocation' => $site . '?portal=open-tilburg&route=/contact']));
		$this->assertSame('/beleid/2026', $paths->ofEvent(event: ['pagePath' => '/index.php/apps/portaliq/site', 'pageLocation' => $site . '?route=%2Fbeleid%2F2026%2F&portal=x']));
		$this->assertSame('/over-ons', $paths->ofEvent(event: ['pageLocation' => $site . '?route=over-ons']));
		// No route parameter on the built-in site: its home page.
		$this->assertSame('/', $paths->ofEvent(event: ['pagePath' => '/index.php/apps/portaliq/site', 'pageLocation' => $site . '?portal=open-tilburg']));
		$this->assertSame('/', $paths->ofEvent(event: ['pagePath' => '/apps/portaliq/site/']));
	}//end testTheBuiltInSiteIsKeyedByItsRouteParameter()


	/**
	 * A site on its own domain keeps its path, a trailing slash dropped.
	 *
	 * @return void
	 */
	public function testAPathLosesItsTrailingSlashButTheRootStays(): void {
		$paths = new TrafficPagePath();

		$this->assertSame('/contact', $paths->ofEvent(event: ['pagePath' => '/contact/', 'pageLocation' => 'https://open-tilburg.nl/contact/']));
		$this->assertSame('/', $paths->ofEvent(event: ['pagePath' => '/', 'pageLocation' => 'https://open-tilburg.nl/']));
		$this->assertSame('/woo', $paths->ofEvent(event: ['pageLocation' => 'https://open-tilburg.nl/woo?q=1']));
		$this->assertSame('/', $paths->ofEvent(event: []));
	}//end testAPathLosesItsTrailingSlashButTheRootStays()


	/**
	 * A page's stored route normalises to the same key.
	 *
	 * @return void
	 */
	public function testAPagesRouteNormalisesToTheSameKey(): void {
		$paths = new TrafficPagePath();

		$this->assertSame('/', $paths->route(value: '/'));
		$this->assertSame('/', $paths->route(value: ''));
		$this->assertSame('/contact', $paths->route(value: 'contact/'));
		$this->assertSame('/beleid/2026', $paths->route(value: ' /beleid/2026 '));
	}//end testAPagesRouteNormalisesToTheSameKey()
}//end class
