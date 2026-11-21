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

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Signin\SiteReturnAddress;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A login started on the public site returns to the page it came from, and
 * to nothing outside the site route.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
 */
class SiteReturnAddressTest extends TestCase {

	/**
	 * The helper on a host whose verified domain is the given entry.
	 *
	 * @param array<string, mixed>|null $domain The serving domain, or null for an unknown host.
	 *
	 * @return SiteReturnAddress
	 */
	private function address(?array $domain = null): SiteReturnAddress {
		$resolver = $this->createMock(PortalResolverInterface::class);
		$resolver->method('servingDomain')->willReturn($domain);

		return new SiteReturnAddress($resolver, $this->createMock(IRequest::class));
	}//end address()

	/**
	 * On a host that serves the site at its root, a short page address is kept.
	 *
	 * @return void
	 */
	public function testAShortAddressIsKeptOnAHostServedAtRoot(): void {
		$address = $this->address(domain: ['host' => 'www.zuiddrecht.nl', 'servedAtRoot' => true]);
		$this->assertSame('/afval', $address->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$this->assertSame('/mijn/inbox?tab=2', $address->accept(candidate: '/mijn/inbox?tab=2', sitePath: '/apps/portaliq/site'));
		$this->assertSame('/', $address->accept(candidate: '/', sitePath: '/apps/portaliq/site'));
		$this->assertSame('/apps/portaliq/site?portal=x', $address->accept(candidate: '/apps/portaliq/site?portal=x', sitePath: '/apps/portaliq/site'));
	}//end testAShortAddressIsKeptOnAHostServedAtRoot()

	/**
	 * A short address is refused where the host does not serve the site at
	 * its root: an unknown host, a domain without the setting, or a resolver
	 * that fails.
	 *
	 * @return void
	 */
	public function testAShortAddressIsRefusedElsewhere(): void {
		$this->assertSame('', $this->address()->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$this->assertSame('', $this->address(domain: ['host' => 'x.nl', 'servedAtRoot' => false])->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$this->assertSame('', $this->address(domain: ['host' => 'x.nl', 'servedAtRoot' => 'true'])->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));

		$failing = $this->createMock(PortalResolverInterface::class);
		$failing->method('servingDomain')->willThrowException(new RuntimeException('register down'));
		$this->assertSame('', (new SiteReturnAddress($failing, $this->createMock(IRequest::class)))->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
	}//end testAShortAddressIsRefusedElsewhere()

	/**
	 * On a host served at root, a Nextcloud path, a dot segment and every way
	 * out of the origin are still refused.
	 *
	 * @return void
	 */
	public function testAHostServedAtRootRefusesWhatIsNotASitePage(): void {
		$address = $this->address(domain: ['host' => 'www.zuiddrecht.nl', 'servedAtRoot' => true]);
		$refused = [
			'',
			'afval',
			'//evil.example/afval',
			'/\\evil.example',
			'https://evil.example/afval',
			'/afval#token=x',
			'/javascript:alert(1)',
			'/afval/../index.php/apps/files',
			'/./afval',
			'/index.php/apps/files',
			'/apps/files',
			'/apps/portaliq/sites',
			'/ocs/v2.php/cloud/user',
			'/remote.php/dav',
			'/login',
			'/.well-known/caldav',
			"/afval\r\nLocation: https://evil.example",
		];
		foreach ($refused as $candidate) {
			$this->assertSame('', $address->accept(candidate: $candidate, sitePath: '/apps/portaliq/site'), $candidate);
		}
	}//end testAHostServedAtRootRefusesWhatIsNotASitePage()

	/**
	 * A page on the site route is kept, with its portal and in-site route.
	 *
	 * @return void
	 */
	public function testAPageOnTheSiteIsKept(): void {
		$page = '/apps/portaliq/site?portal=wilgenboom&route=%2Fmijn%2Finbox';
		$this->assertSame($page, $this->address()->accept(candidate: $page, sitePath: '/apps/portaliq/site'));
		$this->assertSame('/apps/portaliq/site', $this->address()->accept(candidate: '/apps/portaliq/site', sitePath: '/apps/portaliq/site'));
	}//end testAPageOnTheSiteIsKept()

	/**
	 * The front controller is optional on both sides.
	 *
	 * @return void
	 */
	public function testTheFrontControllerIsOptional(): void {
		$this->assertSame(
			'/apps/portaliq/site?portal=x',
			$this->address()->accept(candidate: '/apps/portaliq/site?portal=x', sitePath: '/index.php/apps/portaliq/site')
		);
		$this->assertSame(
			'/index.php/apps/portaliq/site?portal=x',
			$this->address()->accept(candidate: '/index.php/apps/portaliq/site?portal=x', sitePath: '/apps/portaliq/site')
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
			$this->assertSame('', $this->address()->accept(candidate: $candidate, sitePath: '/apps/portaliq/site'), $candidate);
		}
	}//end testEveryOtherAddressIsRefused()
}//end class
