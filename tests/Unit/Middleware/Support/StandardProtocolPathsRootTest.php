<?php

/**
 * Unit tests for how a request path is cut to the app root.
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
 * The app root is this app's segment followed by a slash, the first one in
 * the path, and an app whose name only starts the same is another app.
 *
 * @covers \OCA\Portaliq\Middleware\Support\StandardProtocolPaths
 *
 * @spec openspec/specs/api-contract/spec.md
 */
class StandardProtocolPathsRootTest extends TestCase {

	public function testAnAppWhoseNameStartsTheSameIsAnotherApp(): void {
		$this->assertSame('/apps/portaliqx/api/health', StandardProtocolPaths::appRelative(path: '/apps/portaliqx/api/health'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/apps/portaliqx/api/health'));
	}//end testAnAppWhoseNameStartsTheSameIsAnotherApp()

	public function testTheAppRootWithoutATrailingSlashIsNotCut(): void {
		$this->assertSame('/index.php/apps/portaliq', StandardProtocolPaths::appRelative(path: '/index.php/apps/portaliq'));
		$this->assertSame('/', StandardProtocolPaths::appRelative(path: '/apps/portaliq/'));
		$this->assertSame('/', StandardProtocolPaths::appRelative(path: ''));
	}//end testTheAppRootWithoutATrailingSlashIsNotCut()

	public function testTheFirstAppRootIsTheOneCut(): void {
		$this->assertSame('/x/apps/portaliq/api/health', StandardProtocolPaths::appRelative(path: '/apps/portaliq/x/apps/portaliq/api/health'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/apps/portaliq/x/apps/portaliq/api/health'));
	}//end testTheFirstAppRootIsTheOneCut()

	public function testAPatternMatchesTheWholeRelativePathOnly(): void {
		$this->assertTrue(StandardProtocolPaths::matches(path: '/index.php/apps/portaliq/api/traffic-summary.js'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/api/traffic-summary.js.map'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/api/traffic-.js'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/portal/api/session/saml/callback'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/x/portal/api/session/oidc/callback'));
		$this->assertFalse(StandardProtocolPaths::matches(path: '/api/metrics/'));
	}//end testAPatternMatchesTheWholeRelativePathOnly()
}//end class
