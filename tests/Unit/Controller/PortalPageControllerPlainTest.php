<?php

/**
 * The plain version of a site page, `/site/plain`, for a browser without
 * JavaScript (site-honest-without-javascript REQ-SHJ-002): public and rate
 * limited like `/site`, read as an anonymous visitor whatever the request
 * carries, 404 for a route without a published page, canonical to `/site`.
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
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.1
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.5
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\Cms\PlainCategoryNames;
use OCA\Portaliq\Service\Cms\PlainDates;
use OCA\Portaliq\Service\Cms\PlainMarkdown;
use OCA\Portaliq\Service\Cms\PlainPageRenderer;
use OCA\Portaliq\Service\Cms\PlainPublicationDetail;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainPublicationSearch;
use OCA\Portaliq\Service\Cms\PlainTextBlocks;
use OCA\Portaliq\Service\Cms\PlainWidgetLabels;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Tests\Unit\Service\Cms\PlainTestWords;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PortalPageControllerPlainTest extends TestCase {

	/**
	 * The published pages the content read answers, by route.
	 *
	 * @var array<string, array>
	 */
	private array $pages = [];

	/**
	 * Every audience the content read was asked for.
	 *
	 * @var list<string>
	 */
	private array $audiences = [];

	/**
	 * `plain()` carries the same attributes as `site()`: public, no CSRF
	 * token, no admin, and the same anonymous rate limit. Its route sits
	 * before `sitePath`, which would otherwise read `plain` as a page.
	 *
	 * @return void
	 */
	public function testThePlainPageIsPublicAndRateLimited(): void {
		$plain = new ReflectionMethod(PortalPageController::class, 'plain');
		$site = new ReflectionMethod(PortalPageController::class, 'site');
		foreach ([PublicPage::class, NoCSRFRequired::class, NoAdminRequired::class] as $attribute) {
			$this->assertCount(1, $plain->getAttributes($attribute), $attribute);
		}

		$limit = $plain->getAttributes(AnonRateLimit::class);
		$this->assertCount(1, $limit);
		$this->assertSame($site->getAttributes(AnonRateLimit::class)[0]->getArguments(), $limit[0]->getArguments());

		$response = $this->controller()->plain();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('site-plain', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_BLANK, $response->getRenderAs());
		$this->assertStringContainsString("frame-ancestors 'none'", $response->getContentSecurityPolicy()->buildPolicy());

		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$plainRoute = strpos($routes, "'portalPage#plain', 'url' => '/site/plain', 'verb' => 'GET'");
		$this->assertNotFalse($plainRoute);
		$this->assertLessThan(strpos($routes, "'portalPage#sitePath'"), $plainRoute);
	}//end testThePlainPageIsPublicAndRateLimited()

	/**
	 * A request carrying a signed-in resident's session still reads every
	 * page and menu as `anonymous`: the plain page asks for no session at all.
	 *
	 * @return void
	 */
	public function testItReadsAsAnonymousEvenWithASession(): void {
		$this->pages['/over-ons'] = ['title' => 'Over ons', 'summary' => '', 'body' => ['type' => 'markdown', 'markdown' => 'Wij zijn de gemeente.']];

		$response = $this->controller(route: '/over-ons', cookies: ['nc_session_id' => 'abc', 'oc_sessionPassphrase' => 'x'])->plain();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Over ons', $response->getParams()['plain']['title']);
		$this->assertNotSame([], $this->audiences);
		$this->assertSame(['anonymous'], array_values(array_unique($this->audiences)));
	}//end testItReadsAsAnonymousEvenWithASession()

	/**
	 * A route with no published page, a draft (which the published read
	 * never returns) and a host that serves no portal all answer 404.
	 *
	 * @return void
	 */
	public function testAnUnknownOrDraftRouteIs404(): void {
		$this->pages['/over-ons'] = ['title' => 'Over ons', 'summary' => '', 'body' => ['type' => 'markdown', 'markdown' => 'Tekst']];

		$unknown = $this->controller(route: '/bestaat-niet')->plain();
		$draft = $this->controller(route: '/concept')->plain();
		$noPortal = $this->controller(route: '/over-ons', portal: null)->plain();

		foreach ([$unknown, $draft, $noPortal] as $response) {
			$this->assertSame(404, $response->getStatus());
			$this->assertSame('Page not found', $response->getParams()['plain']['title']);
		}

		$this->assertSame($unknown->getParams()['plain']['blocks'], $draft->getParams()['plain']['blocks']);
	}//end testAnUnknownOrDraftRouteIs404()

	/**
	 * The canonical address of the plain page is the `/site` address of the
	 * same route, and its robots value is the one SiteHead gives that route.
	 *
	 * @return void
	 */
	public function testTheCanonicalIsTheSiteAddress(): void {
		$this->pages['/over-ons'] = ['title' => 'Over ons', 'summary' => '', 'seo' => ['noindex' => true], 'body' => ['type' => 'markdown', 'markdown' => 'Tekst']];

		$params = $this->controller(route: '/over-ons', portalParam: 'demo')->plain()->getParams();

		$this->assertSame('https://example.nl/index.php/apps/portaliq/site?route=%2Fover-ons&portal=demo', $params['head']['canonical']);
		$this->assertSame('noindex', $params['head']['robots']);
		$this->assertSame('/index.php/apps/portaliq/site?route=/over-ons&portal=demo', $params['plain']['fullUrl']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/&portal=demo', $params['plain']['homeUrl']);

		$template = (string)file_get_contents(__DIR__ . '/../../../templates/site-plain.php');
		$this->assertStringContainsString('<link rel="canonical" href="<?php p($head[\'canonical\']); ?>">', $template);
	}//end testTheCanonicalIsTheSiteAddress()

	/**
	 * The controller over doubles of its boundaries and the real plain page.
	 *
	 * @param string                    $route       The `route` parameter.
	 * @param string                    $portalParam The `portal` parameter.
	 * @param array<string, mixed>|null $portal      The portal the host resolves to.
	 * @param array<string, string>     $cookies     Cookies the request carries.
	 *
	 * @return PortalPageController
	 */
	private function controller(
		string $route = '',
		string $portalParam = '',
		?array $portal = ['slug' => 'demo', 'title' => 'Gemeente Demo'],
		array $cookies = []
	): PortalPageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => match ($key) {
				'route' => ($route !== '' ? $route : $default),
				'portal' => ($portalParam !== '' ? $portalParam : $default),
				default => $default,
			}
		);
		$request->method('getHeader')->willReturn('');
		$request->method('getCookie')->willReturnCallback(static fn (string $name) => ($cookies[$name] ?? null));
		$request->method('getRequestUri')->willReturn('/index.php/apps/portaliq/site/plain');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkTo')->willReturnCallback(static fn (string $app, string $file): string => '/apps/' . $app . '/' . $file);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $name, array $params = []): string => match ($name) {
				'portaliq.portalPage.site' => '/index.php/apps/portaliq/site',
				default => '/index.php/apps/portaliq/route/' . $name,
			}
		);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $name, array $params = []): string => 'https://example.nl/index.php/apps/portaliq/site?' . http_build_query($params)
		);

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn($portal);
		$portals->method('localeFor')->willReturnCallback(static fn (?array $portal, string $locale): string => $locale);

		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page', 'menus'])->getMock();
		$reader->method('page')->willReturnCallback(
			function (string $portal, string $route, string $locale, string $audience): ?array {
				$this->audiences[] = $audience;

				return ($this->pages[$route] ?? null);
			}
		);
		$reader->method('menus')->willReturnCallback(
			function (string $portal, string $locale, string $audience): array {
				$this->audiences[] = $audience;

				return [];
			}
		);

		$framing = $this->getMockBuilder(AccessibilityFraming::class)->disableOriginalConstructor()->onlyMethods(['allowsSelf'])->getMock();
		$framing->method('allowsSelf')->willReturn(false);

		$publications = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['search', 'publication'])->getMock();
		$words = PlainTestWords::for($this);
		$plain = new PlainPageRenderer(
			$reader,
			$words,
			new PlainMarkdown(),
			new PlainWidgetLabels(),
			new PlainTextBlocks(new PlainMarkdown()),
			new PlainPublicationSearch($publications, $words, new PlainDates()),
			new PlainPublicationDetail($publications, $words, new PlainDates(), new PlainCategoryNames())
		);

		return new PortalPageController(
			$request,
			$this->createMock(PortalRuntimeConfigResolver::class),
			$urls,
			$portals,
			$this->createMock(PortalThemeResolver::class),
			new SiteHead($reader),
			$this->createMock(PortalNoticeReader::class),
			$framing,
			null,
			$plain
		);
	}//end controller()
}//end class
