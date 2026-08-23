<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * portal-white-label-runtime-config: the shell renders through
 * TemplateResponse::RENDER_AS_BASE with the resolved runtime config passed
 * as a template param, and the CSP's frame-ancestors is built from the
 * resolved Organisation's allowed embed origins — 'none' when empty, NEVER
 * the previous hard-coded '*'. catchAll() renders through the same index()
 * path so every portal URL (not just `/portal`) carries the config.
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.1
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.2
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.3
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */
class PortalPageControllerTest extends TestCase {

	/**
	 * BASE, not PUBLIC. `layout.public.php` emits a VISIBLE `<header
	 * id="header">` carrying the Nextcloud logo and instance title, which on a
	 * white-label portal is another product's brand sitting above the
	 * municipality's own — the plainest contradiction of the very spec this
	 * class cites. `layout.base.php` emits `#content` and nothing else, while
	 * still shipping the CSS, scripts and initial state the shell boots from.
	 *
	 * Asserted by NAME rather than "not public": RENDER_AS_BLANK would also
	 * drop the header, and would do it by shipping no assets at all.
	 */
	public function testIndexRendersPortalTemplateAsBase(): void {
		$controller = $this->controller(orgSlug: '');
		$response = $controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(TemplateResponse::RENDER_AS_BASE, $response->getRenderAs());

	}//end testIndexRendersPortalTemplateAsBase()

	public function testNoAllowedEmbedOriginsYieldsFrameAncestorsNone(): void {
		$controller = $this->controller(orgSlug: '', resolved: ['allowedEmbedOrigins' => []]);
		$response = $controller->index();

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("frame-ancestors 'none';", $policy);
		$this->assertStringNotContainsString('frame-ancestors *;', $policy);

	}//end testNoAllowedEmbedOriginsYieldsFrameAncestorsNone()

	public function testConfiguredAllowedEmbedOriginsAreAppliedNeverWildcard(): void {
		$controller = $this->controller(
			orgSlug: 'gemeente-x',
			resolved: ['allowedEmbedOrigins' => ['https://gemeente-x.example']]
		);
		$response = $controller->index();

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString('frame-ancestors https://gemeente-x.example;', $policy);
		$this->assertStringNotContainsString('frame-ancestors *;', $policy);
		// The 'self' default (allowed BEFORE any tenant configuration is
		// resolved) must not silently persist alongside a configured origin.
		$this->assertStringNotContainsString("frame-ancestors 'self'", $policy);

	}//end testConfiguredAllowedEmbedOriginsAreAppliedNeverWildcard()

	public function testCatchAllDelegatesToIndexForDistinctPaths(): void {
		$controller = $this->controller(orgSlug: '');

		foreach (['contracts/123', 'invoices/456'] as $path) {
			$response = $controller->catchAll($path);
			$this->assertInstanceOf(TemplateResponse::class, $response);
			// Every deep link renders through index(), so the chrome fix has to
			// hold here too — a portal that is clean at `/portal` and branded
			// at `/portal/invoices/456` is still branded to the visitor.
			$this->assertSame(TemplateResponse::RENDER_AS_BASE, $response->getRenderAs());
		}

	}//end testCatchAllDelegatesToIndexForDistinctPaths()

	public function testIndexPassesTheFirstAcceptLanguageTagToTheResolver(): void {
		$received = null;
		$resolver = $this->createMock(PortalOrganisationConfigService::class);
		$resolver->method('resolve')->willReturnCallback(
			function (string $orgSlug, string $locale = 'nl') use (&$received) {
				$received = $locale;
				return ['allowedEmbedOrigins' => []];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'org' ? '' : $default)
		);
		$request->method('getHeader')->willReturnMap([['Accept-Language', 'en-US,en;q=0.9,nl;q=0.8']]);

		// index() does not consult the portal/theme resolvers — that is site()'s
		// job — so they are inert mocks here rather than configured ones.
		(new PortalPageController(
			$request,
			$resolver,
			$this->createMock(IURLGenerator::class),
			$this->createMock(PortalResolver::class),
			$this->createMock(PortalThemeResolver::class)
		))->index();

		$this->assertSame('en-US', $received);

	}//end testIndexPassesTheFirstAcceptLanguageTagToTheResolver()

	/**
	 * The site shell carries no platform chrome AND no platform stylesheet.
	 *
	 * `site()` had NO unit assertion on its render mode at all — the helper
	 * below even referenced a `testSiteRendersSiteTemplateAsPublic` that was
	 * never written, so the reference read as coverage that did not exist.
	 * index() and catchAll() were pinned; the route that replaces them was not.
	 *
	 * This was `RENDER_AS_BASE`, and BASE was not enough. It drops the visible
	 * Nextcloud header but still ships `server.css` and the instance theme
	 * chain, whose rules on `#content` and bare `h1` outrank anything this app
	 * can scope: measured against the reference implementation, the content
	 * column came out 1235px at +50px where the design says 1280 at 0. BLANK
	 * hands the template the entire response, and `templates/site.php` links
	 * only the portal's own assets.
	 *
	 * Asserted by NAME, because the three modes fail differently and only this
	 * one means "we own the document".
	 */
	public function testSiteRendersSiteTemplateAsBlank(): void {
		$controller = $this->controller(orgSlug: '');
		$response = $controller->site();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(TemplateResponse::RENDER_AS_BLANK, $response->getRenderAs());
		// Spelled out, because BASE is the value this used to be and the one a
		// well-meaning revert would restore.
		$this->assertNotSame(TemplateResponse::RENDER_AS_BASE, $response->getRenderAs());

	}//end testSiteRendersSiteTemplateAsBlank()

	/**
	 * A THEMED portal gets BOTH stylesheets, and they are not the same answer.
	 *
	 * `themeStylesheet` names a file in the THEME APP (nldesign); the newer
	 * `nldsStylesheet` names one this app ships. They are resolved by separate
	 * calls and can disagree — a theme with an app-level file but no `--utrecht-*`
	 * token set is exactly the case the second one exists to cover.
	 *
	 * The existing suite only ever exercised the unresolved path (the helper
	 * below mocks the portal resolver to return null), so every statement on
	 * the resolved path was unmeasured while the file read as covered.
	 */
	public function testSiteEmitsBothStylesheetsForAThemedPortal(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'vng'],
			themeStylesheet: 'themes/vng',
			nldsStylesheet: 'themes/vng-tokens'
		);

		$params = $controller->site()->getParams();

		$this->assertSame('themes/vng', $params['themeStylesheet']);
		$this->assertSame('themes/vng-tokens', $params['nldsStylesheet']);

	}//end testSiteEmitsBothStylesheetsForAThemedPortal()

	/**
	 * A THROWING portal resolver yields an UNSTYLED page, not somebody else's brand.
	 *
	 * Both helpers catch `\Throwable` and return ''. That choice is only safe
	 * if it is actually reachable, and it was never executed by a test: a
	 * resolver that throws on an unknown host is the ordinary case in
	 * production, not an exotic one.
	 *
	 * The assertion is that the page renders with NO stylesheet. Falling back
	 * to a default theme instead would put one municipality's colours on
	 * another's portal, which looks correct in every screenshot and is wrong
	 * in the only way that matters.
	 */
	public function testSiteFallsBackToNoStylesheetWhenResolutionThrows(): void {
		$controller = $this->controller(orgSlug: '', portalResolverThrows: true);

		$params = $controller->site()->getParams();

		$this->assertSame('', $params['themeStylesheet']);
		$this->assertSame('', $params['nldsStylesheet']);
		// It still renders. A theme failure must not become a 500 on a public
		// government page.
		$this->assertInstanceOf(TemplateResponse::class, $controller->site());

	}//end testSiteFallsBackToNoStylesheetWhenResolutionThrows()

	/**
	 * The standalone shell renders the whole document, so it needs a `lang`.
	 *
	 * With no `Accept-Language` the answer is `nl`, not the empty string —
	 * `<html lang="">` is a WCAG failure, and an empty attribute is the shape
	 * a missing header would otherwise produce.
	 */
	public function testSiteAlwaysPassesANonEmptyLocale(): void {
		$this->assertSame('nl', $this->controller(orgSlug: '')->site()->getParams()['locale']);

	}//end testSiteAlwaysPassesANonEmptyLocale()

	/**
	 * Build a controller.
	 *
	 * @param string      $orgSlug             The `org` request param.
	 * @param array       $resolved            Overrides for the resolved org config.
	 * @param array|null  $portal              The portal the portal resolver returns.
	 * @param string|null $themeStylesheet     What `stylesheetFor()` returns.
	 * @param string|null $nldsStylesheet      What `nldsStylesheetFor()` returns.
	 * @param bool        $portalResolverThrows Whether the portal resolver throws.
	 *
	 * @return PortalPageController The controller under test.
	 */
	private function controller(
		string $orgSlug,
		array $resolved = [],
		?array $portal = null,
		?string $themeStylesheet = null,
		?string $nldsStylesheet = null,
		bool $portalResolverThrows = false,
		?string $logoFile = null,
		?string $themeAppId = 'thematiq'
	): PortalPageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'org' ? $orgSlug : $default)
		);
		$request->method('getHeader')->willReturn('');

		$default = [
			'organisationName' => 'Portaliq',
			'organisationSlug' => '',
			'theme' => 'default',
			'logo' => null,
			'oidcProviders' => [],
			'featureFlags' => [],
			'allowedEmbedOrigins' => [],
			'apiBase' => '/index.php/apps/portaliq/portal/api',
			'audience' => 'supplier',
			'locale' => 'nl',
		];

		$resolver = $this->createMock(PortalOrganisationConfigService::class);
		$resolver->method('resolve')->willReturn(array_merge($default, $resolved));

		// The site renderer (`site()`) needs a URL generator to hand the
		// content API base to the client. Returning the real route shape here
		// rather than an empty string keeps the assertion in
		// testSiteRendersSiteTemplateAsBase meaningful.
		$urlGenerator = $this->createMock(IURLGenerator::class);
		// `linkTo(app, path)` is what turns the theme app's relative logo path
		// into a URL the browser can actually fetch; without it the assertion
		// below would pass on an empty string.
		$urlGenerator->method('linkTo')
			->willReturnCallback(
				static fn (string $app, string $file): string => ('/apps/' . $app . '/' . $file)
			);
		$urlGenerator->method('linkToRoute')
			->willReturn('/index.php/apps/portaliq/api/content/site');

		// The portal + theme resolvers decide which token stylesheets `site()`
		// emits. The DEFAULT is still "resolve nothing", so every pre-existing
		// assertion keeps measuring what it always did: an unthemed shell. The
		// parameters above let a single test opt into the resolved path or the
		// throwing one, which is what the callers below exercise.
		$portalResolver = $this->createMock(PortalResolver::class);
		if ($portalResolverThrows === true) {
			$portalResolver->method('resolve')
				->willThrowException(new \RuntimeException('unknown host'));
		} else {
			$portalResolver->method('resolve')->willReturn($portal);
		}

		$themeResolver = $this->createMock(PortalThemeResolver::class);
		$themeResolver->method('stylesheetFor')->willReturn($themeStylesheet);
		$themeResolver->method('nldsStylesheetFor')->willReturn($nldsStylesheet);
		$themeResolver->method('logoFileFor')->willReturn($logoFile);
		// The id the theme app is installed under on this instance. The app is
		// mid-rename (`nldesign` -> `thematiq`), so the controller asks rather
		// than compiling one in — a URL built for an id nothing answers to is a
		// 404 logo on an otherwise intact page.
		$themeResolver->method('themeAppId')->willReturn($themeAppId);

		return new PortalPageController(
			$request,
			$resolver,
			$urlGenerator,
			$portalResolver,
			$themeResolver
		);
	}//end controller()


	/**
	 * A themed portal whose set ships a logo gets an ABSOLUTE url for it.
	 *
	 * WHY THE CONTROLLER RESOLVES THIS AT ALL: token sets declare
	 * `--nldesign-logo-url` relative to the token file, and a browser resolves
	 * a relative `url()` inside a custom property against the stylesheet
	 * CONSUMING it — this app's bundled CSS, not the theme app's. Measured on a
	 * live rig, the header requested
	 * `/custom_apps/portaliq/img/logos/opencatalogi.svg` and rendered no logo,
	 * while every token in the chain held the right value.
	 *
	 * So the app that knows where the theme app lives resolves it once, here.
	 */
	public function testSiteEmitsAnAbsoluteLogoUrlForAThemedPortal(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'opencatalogi'],
			themeStylesheet: 'tokens/opencatalogi',
			logoFile: 'img/logos/opencatalogi.svg'
		);

		$params = $controller->site()->getParams();

		$this->assertNotSame('', $params['themeLogoUrl']);
		$this->assertStringContainsString('img/logos/opencatalogi.svg', $params['themeLogoUrl']);

	}//end testSiteEmitsAnAbsoluteLogoUrlForAThemedPortal()


	/**
	 * With no theme app installed there is no id to build a logo URL against,
	 * so the controller emits '' rather than a URL for an app that is not
	 * there. `linkTo()` will happily build one for an unknown id — the result
	 * is a 404 image on an otherwise intact page, which is exactly the kind of
	 * quiet breakage this app refuses to ship.
	 *
	 * @return void
	 */
	public function testSiteEmitsNoLogoUrlWhenNoThemeAppIsInstalled(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'opencatalogi'],
			themeStylesheet: 'tokens/opencatalogi',
			logoFile: 'img/logos/opencatalogi.svg',
			themeAppId: null
		);

		$this->assertSame('', $controller->site()->getParams()['themeLogoUrl']);

	}//end testSiteEmitsNoLogoUrlWhenNoThemeAppIsInstalled()


	/**
	 * A set with NO logo file emits an empty string, not a path that 404s.
	 *
	 * A broken image is indistinguishable on screen from having no logo, and
	 * moves the failure somewhere only the browser sees.
	 */
	public function testSiteEmitsNoLogoUrlWhenTheSetShipsNone(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'vng'],
			themeStylesheet: 'tokens/vng',
			logoFile: null
		);

		$this->assertSame('', $controller->site()->getParams()['themeLogoUrl']);

	}//end testSiteEmitsNoLogoUrlWhenTheSetShipsNone()


	/**
	 * An UNTHEMED portal has no logo either.
	 *
	 * The mark must never outlive the theme: a portal rendering unstyled while
	 * still wearing another brand's logo is the confusing half-state the
	 * resolver's null-rather-than-default posture exists to avoid.
	 */
	public function testAnUnthemedPortalEmitsNoLogoUrl(): void {
		$controller = $this->controller(orgSlug: '', portalResolverThrows: true);

		$this->assertSame('', $controller->site()->getParams()['themeLogoUrl']);

	}//end testAnUnthemedPortalEmitsNoLogoUrl()


}//end class
