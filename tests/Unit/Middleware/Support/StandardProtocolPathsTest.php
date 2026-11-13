<?php

/**
 * Unit tests for the paths that speak a protocol defined outside the app.
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

use OCA\Portaliq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\TestCase;

/**
 * A standard protocol path is recognised whether it arrives app-relative or
 * as a full request path under the app root, and nothing else is.
 *
 * @covers \OCA\Portaliq\Middleware\Support\StandardProtocolPaths
 *
 * @spec openspec/specs/api-contract/spec.md
 */
class StandardProtocolPathsTest extends TestCase {

	public function testAFullRequestPathIsCutToThePartAfterTheAppRoot(): void {
		$this->assertSame('/api/health', StandardProtocolPaths::appRelative(path: '/index.php/apps/portaliq/api/health'));
		$this->assertSame('/api/metrics', StandardProtocolPaths::appRelative(path: '/apps/portaliq/api/metrics'));

	}//end testAFullRequestPathIsCutToThePartAfterTheAppRoot()

	public function testAnAppRelativePathKeepsOneLeadingSlash(): void {
		$this->assertSame('/api/health', StandardProtocolPaths::appRelative(path: 'api/health'));
		$this->assertSame('/api/health', StandardProtocolPaths::appRelative(path: '///api/health'));
		// Another app's root is not this app's: the path stays as it is.
		$this->assertSame('/apps/other/api/health', StandardProtocolPaths::appRelative(path: '/apps/other/api/health'));

	}//end testAnAppRelativePathKeepsOneLeadingSlash()

	public function testEachDeclaredProtocolMatchesUnderTheAppRoot(): void {
		$this->assertTrue(StandardProtocolPaths::matches(path: '/index.php/apps/portaliq/api/traffic-beacon.js'));
		$this->assertTrue(StandardProtocolPaths::matches(path: '/apps/portaliq/portal/api/session/oidc/callback'));
		$this->assertTrue(StandardProtocolPaths::matches(path: '/portal/api/session/broker/return'));
		$this->assertTrue(StandardProtocolPaths::matches(path: '/api/health'));
		$this->assertTrue(StandardProtocolPaths::matches(path: '/api/metrics'));

	}//end testEachDeclaredProtocolMatchesUnderTheAppRoot()

	public function testAnOrdinaryApiPathIsNoProtocolPath(): void {
		$this->assertFalse(StandardProtocolPaths::matches(path: '/index.php/apps/portaliq/api/cases'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/api/health/extra'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/api/traffic-Beacon.js'));
		// A protocol path under another app's root is not this app's.
		$this->assertFalse(StandardProtocolPaths::matches(path: '/apps/other/api/health'));

	}//end testAnOrdinaryApiPathIsNoProtocolPath()
}//end class
