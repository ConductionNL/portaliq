<?php

/**
 * PortalPageControllerHostRootTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The site at the root of a portal's own host, asserted from the caller
 * (site-at-the-root-of-a-portal-host).
 *
 * `PortalPageControllerTest` mocks the portal resolver, so it cannot tell a
 * controller that asks the resolver from one that does not. Here the REAL
 * resolver sits behind the controller, over the portals an instance would
 * hold, and the only thing a test changes is the host Nextcloud resolved for
 * the request. What the response carries is then the whole chain: host,
 * verified domain, portal, addressing, landing page, status.
 *
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-host-that-resolves-no-portal-must-get-no-site-req-srh-003
 */
class PortalPageControllerHostRootTest extends TestCase {

	/**
	 * The route and canonical address the head was asked for, per request.
	 *
	 * @var array<int, array{route: string, canonical: string, portal: string}>
	 */
	private array $heads = [];


	/**
	 * A verified domain that declares it serves the site at its root gets the
	 * portal, the short addressing and a short canonical address, for `/` and
	 * for a page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function testAVerifiedRootDomainServesItsPortalWithShortAddresses(): void {
		$response = $this->controller(host: 'www.gemeente.example')->sitePath('afval/grofvuil');
		$config   = $response->getParams()['portalConfig'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('gemeente', $config['resolvedPortal']);
		$this->assertSame('Gemeente Voorbeeld', $config['title']);
		$this->assertSame(['root' => true, 'siteRoute' => '/index.php/apps/portaliq/site', 'landingRoute' => ''], $config['addressing']);
		$this->assertSame(
			['route' => '/afval/grofvuil', 'canonical' => 'https://www.gemeente.example/afval/grofvuil', 'portal' => 'gemeente'],
			$this->heads[0]
		);

		$home = $this->controller(host: 'www.gemeente.example')->sitePath('');
		$this->assertSame(Http::STATUS_OK, $home->getStatus());
		$this->assertSame('/', end($this->heads)['route']);
		$this->assertSame('https://www.gemeente.example/', end($this->heads)['canonical']);
	}//end testAVerifiedRootDomainServesItsPortalWithShortAddresses()


	/**
	 * A second domain of the same portal opens on its own page: the host of
	 * the signed-in area lands there, the main host lands on home.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-domain-may-open-on-a-page-of-its-own-req-srh-002
	 */
	public function testASecondDomainOfThePortalOpensOnItsOwnPage(): void {
		$response = $this->controller(host: 'mijn.gemeente.example')->sitePath('');
		$config   = $response->getParams()['portalConfig'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('gemeente', $config['resolvedPortal']);
		$this->assertSame('/mijn', $config['addressing']['landingRoute']);
		$this->assertTrue($config['addressing']['root']);
		$this->assertSame('/mijn', $this->heads[0]['route']);
		$this->assertSame('https://mijn.gemeente.example/mijn', $this->heads[0]['canonical']);

		// A named page wins over the landing page.
		$this->controller(host: 'mijn.gemeente.example')->sitePath('mijn/zaken');
		$this->assertSame('/mijn/zaken', end($this->heads)['route']);

		// The long address of the same host opens the landing page too.
		$this->controller(host: 'mijn.gemeente.example')->site();
		$this->assertSame('/mijn', end($this->heads)['route']);
	}//end testASecondDomainOfThePortalOpensOnItsOwnPage()


	/**
	 * An unknown host, an unverified domain and the host of the workplace
	 * resolve to NO portal: 404, no title, no theme, no addressing, no
	 * landing page. Never the first portal, never a default one.
	 *
	 * @return void
	 */
	public function testAHostThatResolvesNoPortalGetsNoSite(): void {
		foreach (['nobody.example', 'claimed.example', 'cloud.example', ''] as $host) {
			$this->heads = [];
			$response    = $this->controller(host: $host)->sitePath('afval');
			$params      = $response->getParams();
			$config      = $params['portalConfig'];

			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $host);
			$this->assertSame('', $config['resolvedPortal'], $host);
			$this->assertSame('', $config['portal'], $host);
			$this->assertSame('', $config['title'], $host);
			$this->assertSame([], $config['portalNotices'], $host);
			$this->assertSame('', $params['themeStylesheet'], $host);
			$this->assertSame(['root' => false, 'siteRoute' => '/index.php/apps/portaliq/site', 'landingRoute' => ''], $config['addressing'], $host);
			$this->assertSame('', $this->heads[0]['portal'], $host);
			$this->assertStringStartsWith('https://cloud.example/index.php/', $this->heads[0]['canonical'], $host);

			// The bare address of such a host opens no landing page either.
			$this->controller(host: $host)->sitePath('');
			$this->assertSame('/', end($this->heads)['route'], $host);
		}
	}//end testAHostThatResolvesNoPortalGetsNoSite()


	/**
	 * A forged `Host` or `X-Forwarded-Host` header selects nothing. The
	 * controller's chain only ever reads the host Nextcloud resolved, which
	 * here is the workplace.
	 *
	 * @return void
	 */
	public function testAForgedHostHeaderSelectsNoSite(): void {
		$response = $this->controller(host: 'cloud.example', forgedHost: 'www.gemeente.example')->sitePath('');
		$config   = $response->getParams()['portalConfig'];

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('', $config['resolvedPortal']);
		$this->assertSame('', $config['title']);
		$this->assertFalse($config['addressing']['root']);
	}//end testAForgedHostHeaderSelectsNoSite()


	/**
	 * A request that names another portal on a root-serving host is served
	 * as that portal, with the long addressing: one portal's host settings
	 * never shape another portal's pages. An unknown slug is no portal at
	 * all, and does not fall back to the host's portal.
	 *
	 * @return void
	 */
	public function testANamedPortalOnAnotherPortalsHostKeepsTheLongAddressing(): void {
		$response = $this->controller(host: 'mijn.gemeente.example', portalParam: 'school')->sitePath('');
		$config   = $response->getParams()['portalConfig'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('school', $config['resolvedPortal']);
		$this->assertSame(['root' => false, 'siteRoute' => '/index.php/apps/portaliq/site', 'landingRoute' => ''], $config['addressing']);
		$this->assertSame('/', $this->heads[0]['route']);

		$unknown = $this->controller(host: 'mijn.gemeente.example', portalParam: 'does-not-exist')->sitePath('');
		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
		$this->assertSame('', $unknown->getParams()['portalConfig']['resolvedPortal']);
	}//end testANamedPortalOnAnotherPortalsHostKeepsTheLongAddressing()


	/**
	 * A verified domain that does not declare root serving keeps the long
	 * addressing: without the proxy rule a short link would not resolve.
	 *
	 * @return void
	 */
	public function testAVerifiedDomainWithoutTheDeclarationKeepsLongAddresses(): void {
		$response = $this->controller(host: 'school.example')->sitePath('rooster');
		$config   = $response->getParams()['portalConfig'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('school', $config['resolvedPortal']);
		$this->assertFalse($config['addressing']['root']);
		$this->assertSame('/rooster', $this->heads[0]['route']);
		$this->assertStringStartsWith('https://cloud.example/index.php/apps/portaliq/site?', $this->heads[0]['canonical']);
	}//end testAVerifiedDomainWithoutTheDeclarationKeepsLongAddresses()


	/**
	 * The portals of the instance: one with two root-serving domains (the
	 * second opens on its own page) and an unverified domain that claims the
	 * same, and one on a plain verified domain.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function portals(): array {
		return [
			[
				'slug'    => 'school',
				'title'   => 'Basisschool Voorbeeld',
				'theme'   => 'school',
				'domains' => [['hostname' => 'school.example', 'verified' => true]],
			],
			[
				'slug'    => 'gemeente',
				'title'   => 'Gemeente Voorbeeld',
				'theme'   => 'gemeente',
				'domains' => [
					['hostname' => 'www.gemeente.example', 'verified' => true, 'servedAtRoot' => true],
					['hostname' => 'mijn.gemeente.example', 'verified' => true, 'servedAtRoot' => true, 'landingRoute' => '/mijn'],
					['hostname' => 'claimed.example', 'verified' => false, 'servedAtRoot' => true, 'landingRoute' => '/mijn'],
				],
			],
		];
	}//end portals()


	/**
	 * The controller over the real portal resolver.
	 *
	 * @param string $host        The host Nextcloud resolved for the request.
	 * @param string $portalParam The `portal` request parameter, or ''.
	 * @param string $forgedHost  A host a client put in its headers, or ''.
	 *
	 * @return PortalPageController
	 */
	private function controller(string $host, string $portalParam = '', string $forgedHost = ''): PortalPageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn($host);
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getInsecureServerHost')->willReturn($forgedHost);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (in_array(strtolower($name), ['host', 'x-forwarded-host'], true) === true ? $forgedHost : '')
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'portal' && $portalParam !== '' ? $portalParam : $default)
		);

		$portals     = $this->portals();
		$objects     = new class($portals) {
			/**
			 * @param array<int, array<string, mixed>> $rows The published portals.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);
		$resolver = new PortalResolver($container, $this->createMock(LoggerInterface::class), $context);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkTo')->willReturn('/apps/x/y');
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $name): string => ($name === 'portaliq.portalPage.site' ? '/index.php/apps/portaliq/site' : '/index.php/apps/portaliq/api/content/site')
		);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $name, array $params = []): string => ('https://cloud.example/index.php/apps/portaliq/site?' . http_build_query($params))
		);

		$config = $this->createMock(PortalRuntimeConfigResolver::class);
		$config->method('runtimeConfigFor')->willReturn([]);

		// The theme a portal names is its own stylesheet, so a borrowed brand
		// would show up as another portal's sheet.
		$themes = $this->createMock(PortalThemeResolver::class);
		$themes->method('stylesheetFor')->willReturnCallback(static fn (string $theme): string => ('tokens/' . $theme));
		$themes->method('shippedStylesheets')->willReturn(['bridge' => null, 'fonts' => null]);
		$themes->method('themeAppId')->willReturn('thematiq');

		$head = $this->createMock(SiteHead::class);
		$head->method('for')->willReturnCallback(
			function (?array $portal, string $route, string $locale, string $canonical): array {
				$this->heads[] = ['route' => $route, 'canonical' => $canonical, 'portal' => (string)($portal['slug'] ?? '')];

				return ['title' => '', 'description' => '', 'robots' => '', 'canonical' => $canonical, 'ogImage' => ''];
			}
		);

		$framing = $this->getMockBuilder(AccessibilityFraming::class)->disableOriginalConstructor()->onlyMethods(['allowsSelf'])->getMock();
		$framing->method('allowsSelf')->willReturn(false);

		return new PortalPageController(
			$request,
			$config,
			$urlGenerator,
			$resolver,
			$themes,
			$head,
			$this->createMock(PortalNoticeReader::class),
			$framing
		);
	}//end controller()
}//end class
