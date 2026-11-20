<?php

/**
 * The external portal edge carries no MCP surface (ADR-046).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-mcp-tools-are-unreachable-from-the-external-portal-edge
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Audits the route table and the edge controllers: none of them reaches a
 * tool. MCP listing and invocation live on OpenRegister's internal
 * Nextcloud-authenticated surface only.
 */
#[CoversNothing]
class PortalEdgeMcpTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private const ROOT = (__DIR__ . '/../../..');

	/**
	 * What reaching the MCP surface looks like in source.
	 *
	 * @var string
	 */
	private const MCP_PATTERN = '/OCA\\\\(OpenRegister|Portaliq)\\\\Mcp\\\\|IMcpToolProvider|PortaliqToolProvider|StaffObjectStore|\bmcp\b/i';

	/**
	 * Every route: no URL is an MCP endpoint, and no `/portal/` route is
	 * served by a controller that reaches the MCP surface.
	 *
	 * @return void
	 */
	public function testNoRouteServesMcp(): void {
		$routes = require self::ROOT . '/appinfo/routes.php';
		$all = array_merge(...array_values(array_filter($routes, 'is_array')));
		$portal = 0;
		foreach ($all as $route) {
			$this->assertDoesNotMatchRegularExpression('/mcp/i', (string)$route['url'], $route['name'] . ' is an MCP route');
			if (str_starts_with((string)$route['url'], '/portal/') === false) {
				continue;
			}

			$portal++;
			$controller = ucfirst(explode('#', (string)$route['name'])[0]) . 'Controller';
			$file = self::ROOT . '/lib/Controller/' . $controller . '.php';
			$this->assertFileExists($file, $route['name'] . ' has no controller file');
			$this->assertDoesNotMatchRegularExpression(self::MCP_PATTERN, (string)file_get_contents($file), $controller . ' serves /portal/ and reaches MCP');
		}

		$this->assertGreaterThan(0, $portal, 'No /portal/ route was audited; the route table moved.');
	}//end testNoRouteServesMcp()

	/**
	 * No public or bearer-guarded controller references the MCP surface.
	 *
	 * @return void
	 */
	public function testNoEdgeControllerReachesMcp(): void {
		$audited = 0;
		foreach (glob(self::ROOT . '/lib/Controller/*.php') as $file) {
			$code = (string)file_get_contents($file);
			if (preg_match('/#\[PublicPage\]|implements[^{]*PortalProtected/', $code) !== 1) {
				continue;
			}

			$audited++;
			$this->assertDoesNotMatchRegularExpression(self::MCP_PATTERN, $code, basename($file) . ' is an edge controller that reaches MCP');
		}

		$this->assertGreaterThan(10, $audited, 'Too few edge controllers were audited; the markers moved.');
	}//end testNoEdgeControllerReachesMcp()

	/**
	 * The bearer guard does not know MCP: it never turns a portal bearer
	 * into a Nextcloud user, which is the only identity a tool accepts.
	 *
	 * @return void
	 */
	public function testTheBearerGuardSignsNoNextcloudUserIn(): void {
		$code = (string)file_get_contents(self::ROOT . '/lib/Middleware/PortalAuthMiddleware.php');

		$this->assertDoesNotMatchRegularExpression(self::MCP_PATTERN, $code);
		$this->assertDoesNotMatchRegularExpression('/->setUser\(|->login\(|setVolatileActiveUser/', $code, 'the bearer guard must not sign a Nextcloud user in');
	}//end testTheBearerGuardSignsNoNextcloudUserIn()
}//end class
