<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service\Signin
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\Signin\SiteReturnAddress;
use PHPUnit\Framework\TestCase;

/**
 * A login started on the public site returns to the page it came from, and
 * to nothing outside the site route.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
class SiteReturnAddressTest extends TestCase {

	/**
	 * A page on the site route is kept, with its portal and in-site route.
	 *
	 * @return void
	 */
	public function testAPageOnTheSiteIsKept(): void {
		$page = '/apps/portaliq/site?portal=wilgenboom&route=%2Fmijn%2Finbox';
		$this->assertSame($page, SiteReturnAddress::accept(candidate: $page, sitePath: '/apps/portaliq/site'));
		$this->assertSame('/apps/portaliq/site', SiteReturnAddress::accept(candidate: '/apps/portaliq/site', sitePath: '/apps/portaliq/site'));
	}//end testAPageOnTheSiteIsKept()

	/**
	 * The front controller is optional on both sides.
	 *
	 * @return void
	 */
	public function testTheFrontControllerIsOptional(): void {
		$this->assertSame(
			'/apps/portaliq/site?portal=x',
			SiteReturnAddress::accept(candidate: '/apps/portaliq/site?portal=x', sitePath: '/index.php/apps/portaliq/site')
		);
		$this->assertSame(
			'/index.php/apps/portaliq/site?portal=x',
			SiteReturnAddress::accept(candidate: '/index.php/apps/portaliq/site?portal=x', sitePath: '/apps/portaliq/site')
		);
	}//end testTheFrontControllerIsOptional()

	/**
	 * Every other address is refused, so a bearer never leaves for another
	 * origin or another app.
	 *
	 * @return void
	 */
	public function testEveryOtherAddressIsRefused(): void {
		$refused = [
			'',
			'https://evil.example/apps/portaliq/site',
			'//evil.example/apps/portaliq/site',
			'/\\evil.example/apps/portaliq/site',
			'/apps/portaliq/site/../../../evil',
			'/apps/portaliq/sites',
			'/apps/portaliq/portal?portal=x',
			'/apps/portaliq/site#token=x',
			'/apps/portaliq/site?route=//evil.example',
			"/apps/portaliq/site?portal=x\r\nLocation: https://evil.example",
			'apps/portaliq/site',
		];
		foreach ($refused as $candidate) {
			$this->assertSame('', SiteReturnAddress::accept(candidate: $candidate, sitePath: '/apps/portaliq/site'), $candidate);
		}
	}//end testEveryOtherAddressIsRefused()
}//end class
