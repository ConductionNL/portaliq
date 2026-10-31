<?php

/**
 * SiteAddressingTest
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

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\SiteAddressing;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How site pages are addressed on the host of a request
 * (site-at-the-root-of-a-portal-host): the short addressing and the landing
 * page come from the verified domain the host matches, and from nowhere else.
 *
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
 */
class SiteAddressingTest extends TestCase {

	/**
	 * A domain that declares it serves the site at its root gets the short
	 * addressing; one that does not, and a request with no domain, get the
	 * long one. Only a literal `true` counts.
	 *
	 * @return void
	 */
	public function testOnlyADeclaringDomainIsServedAtItsRoot(): void {
		$this->assertTrue($this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true, 'servedAtRoot' => true])->forRequest($this->request(), '')['root']);
		$this->assertFalse($this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true])->forRequest($this->request(), '')['root']);
		$this->assertFalse($this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true, 'servedAtRoot' => 'yes'])->forRequest($this->request(), '')['root']);
		$this->assertFalse($this->addressing(domain: null)->forRequest($this->request(), '')['root']);
	}//end testOnlyADeclaringDomainIsServedAtItsRoot()


	/**
	 * No serving domain means no settings: the long addressing, no landing
	 * page. This is what an unknown host and an unverified domain get.
	 *
	 * @return void
	 */
	public function testARequestWithoutAServingDomainGetsTheLongAddressingAndNoLandingPage(): void {
		$addressing = $this->addressing(domain: null);

		$this->assertSame(
			['root' => false, 'siteRoute' => '/index.php/apps/portaliq/site', 'landingRoute' => ''],
			$addressing->forRequest($this->request(), '')
		);
		$this->assertSame('/', $addressing->route($this->request(), ''));
	}//end testARequestWithoutAServingDomainGetsTheLongAddressingAndNoLandingPage()


	/**
	 * A resolver that throws is a request without a domain, not an error and
	 * not somebody else's settings.
	 *
	 * @return void
	 */
	public function testAThrowingResolverGivesTheLongAddressing(): void {
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('servingDomain')->willThrowException(new RuntimeException('register down'));
		$addressing = new SiteAddressing($resolver, $this->urlGenerator());

		$this->assertFalse($addressing->forRequest($this->request(), '')['root']);
		$this->assertSame('', $addressing->forRequest($this->request(), '')['landingRoute']);
	}//end testAThrowingResolverGivesTheLongAddressing()


	/**
	 * The slug the request names travels to the resolver, which is what keeps
	 * the settings of one portal's host off another portal's pages.
	 *
	 * @return void
	 */
	public function testTheNamedPortalIsHandedToTheResolver(): void {
		$request  = $this->request();
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->expects($this->once())->method('servingDomain')->with($request, 'other-portal')->willReturn(null);

		(new SiteAddressing($resolver, $this->urlGenerator()))->forRequest($request, 'other-portal');
	}//end testTheNamedPortalIsHandedToTheResolver()


	/**
	 * The route a request asks for: its path, else `?route=`, else the
	 * landing page of its host, else home.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-domain-may-open-on-a-page-of-its-own-req-srh-002
	 */
	public function testTheRouteIsThePathThenTheParameterThenTheLandingPage(): void {
		$landing = $this->addressing(domain: ['hostname' => 'own.example', 'verified' => true, 'landingRoute' => '/mijn']);

		$this->assertSame('/afval/grofvuil', $landing->route($this->request(route: '/contact'), '', 'afval/grofvuil/'));
		$this->assertSame('/contact', $landing->route($this->request(route: 'contact'), '', ''));
		$this->assertSame('/mijn', $landing->route($this->request(), '', ''));
		$this->assertSame('/mijn', $landing->route($this->request(), '', '/'));
		$this->assertSame('/', $this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true])->route($this->request(), '', ''));
	}//end testTheRouteIsThePathThenTheParameterThenTheLandingPage()


	/**
	 * A landing route is a plain path on the site. Anything that could lead
	 * off the host, or that is not a route at all, is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-domain-may-open-on-a-page-of-its-own-req-srh-002
	 */
	public function testALandingRouteThatIsNotAPlainPathIsDropped(): void {
		$refused = ['//evil.example', 'https://evil.example/', '/\\evil.example', 'mijn', '/mijn?x=1', '/mijn#top', "/mij\nn", '/mijn zaken', '/a//b', 'javascript:alert(1)', '/', '', 42, ['/mijn'], str_repeat('/a', 300)];
		foreach ($refused as $value) {
			$addressing = $this->addressing(domain: ['hostname' => 'own.example', 'verified' => true, 'landingRoute' => $value]);
			$this->assertSame('', $addressing->forRequest($this->request(), '')['landingRoute'], var_export($value, true));
		}

		$kept = $this->addressing(domain: ['hostname' => 'own.example', 'verified' => true, 'landingRoute' => ' /mijn/zaken ']);
		$this->assertSame('/mijn/zaken', $kept->forRequest($this->request(), '')['landingRoute']);
	}//end testALandingRouteThatIsNotAPlainPathIsDropped()


	/**
	 * The canonical address is short on a domain served at its root, built
	 * from the host Nextcloud resolved, and long anywhere else.
	 *
	 * @return void
	 */
	public function testTheCanonicalAddressFollowsTheAddressing(): void {
		$root = $this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true, 'servedAtRoot' => true]);
		$this->assertSame('https://portal.example/afval/grof%20vuil', $root->canonical($this->request(), '', '/afval/grof vuil'));
		$this->assertSame('https://portal.example/', $root->canonical($this->request(), '', '/'));

		$long = $this->addressing(domain: ['hostname' => 'portal.example', 'verified' => true]);
		$this->assertSame(
			'https://cloud.example/index.php/apps/portaliq/site?route=%2Fafval&portal=demo',
			$long->canonical($this->request(), 'demo', '/afval')
		);
	}//end testTheCanonicalAddressFollowsTheAddressing()


	/**
	 * The addressing over a resolver that answers one serving domain.
	 *
	 * @param array<string, mixed>|null $domain The serving domain, or null.
	 *
	 * @return SiteAddressing
	 */
	private function addressing(?array $domain): SiteAddressing {
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('servingDomain')->willReturn($domain);

		return new SiteAddressing($resolver, $this->urlGenerator());
	}//end addressing()


	/**
	 * A request on `portal.example`, with an optional `?route=`.
	 *
	 * @param string $route The `route` parameter, or ''.
	 *
	 * @return IRequest
	 */
	private function request(string $route = ''): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('portal.example');
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'route' && $route !== '' ? $route : $default)
		);

		return $request;
	}//end request()


	/**
	 * A URL generator that answers the site route's two shapes.
	 *
	 * @return IURLGenerator
	 */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/portaliq/site');
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $name, array $params = []): string => ('https://cloud.example/index.php/apps/portaliq/site?' . http_build_query($params))
		);

		return $urlGenerator;
	}//end urlGenerator()
}//end class
