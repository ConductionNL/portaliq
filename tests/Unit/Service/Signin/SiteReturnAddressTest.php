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
		$resolver = $this->createMock(originalClassName: PortalResolverInterface::class);
		$resolver->method('servingDomain')->willReturn($domain);

		return new SiteReturnAddress(portalResolver: $resolver, request: $this->createMock(originalClassName: IRequest::class));
	}//end address()

	/**
	 * On a host that serves the site at its root, a short page address is kept.
	 *
	 * @return void
	 */
	public function testAShortAddressIsKeptOnAHostServedAtRoot(): void {
		$address = $this->address(domain: ['host' => 'www.zuiddrecht.nl', 'servedAtRoot' => true]);
		$this->assertSame(expected: '/afval', actual: $address->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$this->assertSame(expected: '/mijn/inbox?tab=2', actual: $address->accept(candidate: '/mijn/inbox?tab=2', sitePath: '/apps/portaliq/site'));
		$this->assertSame(expected: '/', actual: $address->accept(candidate: '/', sitePath: '/apps/portaliq/site'));
		$long = '/apps/portaliq/site?portal=x';
		$this->assertSame(expected: $long, actual: $address->accept(candidate: $long, sitePath: '/apps/portaliq/site'));
	}//end testAShortAddressIsKeptOnAHostServedAtRoot()

	/**
	 * A short address is refused where the host does not serve the site at
	 * its root: an unknown host, a domain without the setting, or a resolver
	 * that fails.
	 *
	 * @return void
	 */
	public function testAShortAddressIsRefusedElsewhere(): void {
		$this->assertSame(expected: '', actual: $this->address()->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$notAtRoot = $this->address(domain: ['host' => 'x.nl', 'servedAtRoot' => false]);
		$this->assertSame(expected: '', actual: $notAtRoot->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
		$notABool = $this->address(domain: ['host' => 'x.nl', 'servedAtRoot' => 'true']);
		$this->assertSame(expected: '', actual: $notABool->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));

		$failing = $this->createMock(originalClassName: PortalResolverInterface::class);
		$failing->method('servingDomain')->willThrowException(new RuntimeException(message: 'register down'));
		$address = new SiteReturnAddress(portalResolver: $failing, request: $this->createMock(originalClassName: IRequest::class));
		$this->assertSame(expected: '', actual: $address->accept(candidate: '/afval', sitePath: '/apps/portaliq/site'));
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
			$this->assertSame(expected: '', actual: $address->accept(candidate: $candidate, sitePath: '/apps/portaliq/site'), message: $candidate);
		}
	}//end testAHostServedAtRootRefusesWhatIsNotASitePage()

	/**
	 * A page on the site route is kept, with its portal and in-site route.
	 *
	 * @return void
	 */
	public function testAPageOnTheSiteIsKept(): void {
		$page = '/apps/portaliq/site?portal=wilgenboom&route=%2Fmijn%2Finbox';
		$this->assertSame(expected: $page, actual: $this->address()->accept(candidate: $page, sitePath: '/apps/portaliq/site'));
		$site = '/apps/portaliq/site';
		$this->assertSame(expected: $site, actual: $this->address()->accept(candidate: $site, sitePath: $site));
	}//end testAPageOnTheSiteIsKept()

	/**
	 * The front controller is optional on both sides.
	 *
	 * @return void
	 */
	public function testTheFrontControllerIsOptional(): void {
		$this->assertSame(
			expected: '/apps/portaliq/site?portal=x',
			actual: $this->address()->accept(candidate: '/apps/portaliq/site?portal=x', sitePath: '/index.php/apps/portaliq/site')
		);
		$this->assertSame(
			expected: '/index.php/apps/portaliq/site?portal=x',
			actual: $this->address()->accept(candidate: '/index.php/apps/portaliq/site?portal=x', sitePath: '/apps/portaliq/site')
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
			$this->assertSame(expected: '', actual: $this->address()->accept(candidate: $candidate, sitePath: '/apps/portaliq/site'), message: $candidate);
		}
	}//end testEveryOtherAddressIsRefused()
}//end class
