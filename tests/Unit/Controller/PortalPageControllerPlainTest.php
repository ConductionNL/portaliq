<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\Cms\SiteIcon;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\SiteShell;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `GET /site/plain`: the plain version of a site page for a visitor without
 * JavaScript (site-honest-without-javascript REQ-SHJ-002). The controller,
 * SiteShell, SiteHead and the plain renderer are the real classes; the
 * content reader, the portal resolver and the URL generator are doubles.
 *
 * @covers \OCA\Portaliq\Controller\PortalPageController
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PortalPageControllerPlainTest extends TestCase {

	/** @var list<array{route: string, audience: string}> */
	private array $reads = [];

	/** @var list<string> */
	private array $menuAudiences = [];

	/**
	 * @return void
	 */
	public function testThePlainPageIsPublicAndRateLimited(): void {
		$plain = new ReflectionMethod(PortalPageController::class, 'plain');
		$site  = new ReflectionMethod(PortalPageController::class, 'site');
		foreach ([PublicPage::class, NoCSRFRequired::class, NoAdminRequired::class] as $attribute) {
			$this->assertCount(1, $plain->getAttributes($attribute), $attribute);
		}

		$limit     = $plain->getAttributes(AnonRateLimit::class)[0]->getArguments();
		$siteLimit = $site->getAttributes(AnonRateLimit::class)[0]->getArguments();
		$this->assertSame($siteLimit, $limit);

		$response = $this->controller(route: '/over-ons')->plain();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('site-plain', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_BLANK, $response->getRenderAs());
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('frame-policy', $response->getContentSecurityPolicy()->buildPolicy());
	}//end testThePlainPageIsPublicAndRateLimited()

	/**
	 * @return void
	 */
	public function testItReadsAsAnonymousEvenWithASession(): void {
		$params = $this->controller(route: '/over-ons', signedIn: true)->plain()->getParams();

		$this->assertNotSame([], $this->reads);
		foreach ($this->reads as $read) {
			$this->assertSame('anonymous', $read['audience']);
		}

		$this->assertSame(['anonymous'], array_values(array_unique($this->menuAudiences)));
		$this->assertSame('Over ons', $params['plain']['title']);
		$this->assertSame([['name' => 'Over ons', 'href' => '/index.php/apps/portaliq/site/plain?route=%2Fover-ons']], $params['plain']['menu']);
		$this->assertSame('<p>Wij zijn de gemeente.</p>', $params['plain']['blocks'][0]['html']);
	}//end testItReadsAsAnonymousEvenWithASession()

	/**
	 * @return void
	 */
	public function testAnUnknownOrDraftRouteIs404(): void {
		$draft   = $this->controller(route: '/concept', signedIn: true)->plain();
		$missing = $this->controller(route: '/bestaat-niet')->plain();

		$this->assertSame(404, $draft->getStatus());
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame('Page not found', $draft->getParams()['plain']['title']);
		$this->assertSame($draft->getParams()['plain']['blocks'], $missing->getParams()['plain']['blocks']);
		$this->assertSame('', $draft->getParams()['plain']['canonical']);
	}//end testAnUnknownOrDraftRouteIs404()

	/**
	 * @return void
	 */
	public function testTheCanonicalIsTheSiteAddress(): void {
		$params = $this->controller(route: '/over-ons')->plain()->getParams();

		$this->assertSame('https://example.nl/index.php/apps/portaliq/site?route=%2Fover-ons', $params['plain']['canonical']);
		$this->assertSame('index, follow', $params['head']['robots']);
		$this->assertSame('/index.php/apps/portaliq/site?route=%2Fover-ons', $params['plain']['fullUrl']);
	}//end testTheCanonicalIsTheSiteAddress()

	/**
	 * The controller over one portal, `wilgenboom`, publishing `/over-ons`.
	 * `/concept` is a draft: the reader, like the real one, never returns it.
	 *
	 * @param string $route    The route asked for.
	 * @param bool   $signedIn Whether the request carries a session (it must change nothing).
	 *
	 * @return PortalPageController
	 */
	private function controller(string $route, bool $signedIn=false): PortalPageController {
		$this->reads         = [];
		$this->menuAudiences = [];
		$request             = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => match ($key) {
				'route' => $route,
				default => $default,
			}
		);
		$request->method('getHeader')->willReturn('');
		$request->method('getCookie')->willReturn($signedIn === true ? 'session-cookie' : null);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $name, array $params = []): string => match ($name) {
				'portaliq.portalPage.site' => '/index.php/apps/portaliq/site',
				'portaliq.portalPage.plain' => '/index.php/apps/portaliq/site/plain',
				default => '/index.php/apps/portaliq/'.$name,
			}.($params === [] ? '' : '?'.http_build_query($params))
		);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $name, array $params = []): string => 'https://example.nl/index.php/apps/portaliq/site?'.http_build_query($params)
		);
		$urls->method('linkTo')->willReturnCallback(static fn (string $app, string $file): string => '/apps/'.$app.'/'.$file);

		$portal   = ['slug' => 'wilgenboom', 'title' => 'Gemeente Wilgenboom'];
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);
		$resolver->method('localeFor')->willReturnCallback(static fn (?array $p, string $locale): string => $locale);

		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page', 'menus'])->getMock();
		$reader->method('page')->willReturnCallback(
			function (string $portal, string $route, string $locale, string $audience) {
				$this->reads[] = ['route' => $route, 'audience' => $audience];
				if ($portal === 'wilgenboom' && $route === '/over-ons') {
					return ['title' => 'Over ons', 'summary' => '', 'seo' => [], 'body' => ['type' => 'markdown', 'markdown' => 'Wij zijn de gemeente.']];
				}

				return null;
			}
		);
		$reader->method('menus')->willReturnCallback(
			function (string $portal, string $locale, string $audience): array {
				$this->menuAudiences[] = $audience;
				return [
					['title' => 'Hoofd', 'position' => 0, 'items' => [['name' => 'Over ons', 'link' => '/over-ons'], ['name' => 'Kwaad', 'link' => 'javascript:alert(1)']]],
					['title' => 'Voet', 'position' => 1, 'items' => [['name' => 'Privacy', 'link' => '/privacy']]],
				];
			}
		);

		$theme = $this->createMock(PortalThemeResolver::class);
		$theme->method('themeAppId')->willReturn(null);
		$framing = $this->createMock(AccessibilityFraming::class);
		$policy  = $this->createMock(ContentSecurityPolicy::class);
		$policy->method('buildPolicy')->willReturn('frame-policy');
		$framing->method('sitePolicy')->willReturn($policy);
		$library = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['item'])->getMock();

		return new PortalPageController(
			$request,
			$urls,
			new SiteShell(
				request: $request,
				portalResolver: $resolver,
				themeResolver: $theme,
				urlGenerator: $urls,
				siteHead: new SiteHead($reader),
				notices: $this->createMock(PortalNoticeReader::class),
				configResolver: $this->createMock(PortalRuntimeConfigResolver::class),
				siteIcon: new SiteIcon(new MediaReferences($urls, $library), $urls)
			),
			$framing,
			PlainRendererFactory::make(reader: $reader, urlGenerator: $urls)
		);
	}//end controller()
}//end class
