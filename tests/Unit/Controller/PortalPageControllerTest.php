<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\Cms\SiteIcon;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\SiteShell;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The site shell (`/site`) and the retired portal's address (`/portal`), which
 * now answers with a redirect to the site keeping its query string
 * (site-reaches-portal-parity REQ-SRP-048).
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.1
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.2
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.3
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */
class PortalPageControllerTest extends TestCase {

	/** @var AccessibilityFraming|null The framing rule a test sets, or a mock that says no. */
	private ?AccessibilityFraming $framing = null;


	/**
	 * `/portal` answers 302 to the site with the same query string
	 * (site-reaches-portal-parity REQ-SRP-048), so a bookmark, an installed
	 * app or an old mail keeps naming the same portal.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	public function testPortalRedirectsToTheSiteKeepingTheQueryString(): void {
		$cases = [
			'/index.php/apps/portaliq/portal?portal=wilgenboom' => '/index.php/apps/portaliq/site?portal=wilgenboom',
			'/index.php/apps/portaliq/portal?org=gemeente-x&portal=demo' => '/index.php/apps/portaliq/site?org=gemeente-x&portal=demo',
			'/index.php/apps/portaliq/portal' => '/index.php/apps/portaliq/site',
			'/index.php/apps/portaliq/portal?' => '/index.php/apps/portaliq/site',
		];
		foreach ($cases as $uri => $location) {
			$response = $this->controller(orgSlug: '', requestUri: $uri)->index();

			$this->assertInstanceOf(RedirectResponse::class, $response);
			$this->assertSame(Http::STATUS_FOUND, $response->getStatus(), $uri);
			$this->assertSame($location, $response->getRedirectURL(), $uri);
		}

	}//end testPortalRedirectsToTheSiteKeepingTheQueryString()


	/**
	 * A deep link under `/portal/...` lands on the site too, with its query.
	 * The fragment is the browser's to keep: a `Location` without one inherits
	 * the original, so no Location here may carry a `#`.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	public function testPortalDeepLinksRedirectToTheSite(): void {
		foreach (['contracts/123', 'invoices/456'] as $path) {
			$response = $this->controller(
				orgSlug: 'gemeente-x',
				requestUri: '/index.php/apps/portaliq/portal/' . $path . '?org=gemeente-x'
			)->catchAll($path);

			$this->assertInstanceOf(RedirectResponse::class, $response);
			$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
			$this->assertSame('/index.php/apps/portaliq/site?org=gemeente-x', $response->getRedirectURL());
			$this->assertStringNotContainsString('#', $response->getRedirectURL());
		}

	}//end testPortalDeepLinksRedirectToTheSite()


	/**
	 * `?org=` names the same portal on the site as it did on `/portal`: the
	 * organisation's one published portal, whose slug the shell hands the
	 * renderer, so every content call carries `?portal=` (REQ-SRP-048).
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	public function testSiteResolvesTheOrganisationParameterToItsPortal(): void {
		$controller = $this->controller(
			orgSlug: 'gemeente-x',
			portal: ['slug' => 'wilgenboom', 'title' => 'De Wilgenboom'],
			byOrganisation: ['gemeente-x' => ['slug' => 'wilgenboom', 'title' => 'De Wilgenboom']]
		);

		$params = $controller->site()->getParams();

		$this->assertSame('wilgenboom', $params['portalConfig']['portal']);

	}//end testSiteResolvesTheOrganisationParameterToItsPortal()


	/**
	 * `?portal=` wins over `?org=`, and an organisation that names no portal
	 * hands the renderer no slug: never the raw `?org=` value.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	public function testSitePrefersThePortalParameterAndNeverEchoesAnUnknownOrganisation(): void {
		$both = $this->controller(
			orgSlug: 'gemeente-x',
			portalParam: 'demo',
			byOrganisation: ['gemeente-x' => ['slug' => 'wilgenboom']]
		);
		$this->assertSame('demo', $both->site()->getParams()['portalConfig']['portal']);

		$unknown = $this->controller(orgSlug: '"><script>', byOrganisation: []);
		$this->assertSame('', $unknown->site()->getParams()['portalConfig']['portal']);

		$throws = $this->controller(orgSlug: 'gemeente-x', byOrganisationThrows: true);
		$this->assertSame('', $throws->site()->getParams()['portalConfig']['portal']);

	}//end testSitePrefersThePortalParameterAndNeverEchoesAnUnknownOrganisation()

	/**
	 * The notices for signed-in residents (surface `portal`), which `/portal`
	 * carried in its runtime config, travel in the site shell now; a request
	 * that resolves no portal carries none (REQ-SRP-010).
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notices-must-show-above-every-page-req-srp-010
	 */
	public function testSiteCarriesTheSignedInNotices(): void {
		$notice  = ['id' => 'n-1', 'message' => 'Onderhoud', 'level' => 'info', 'linkLabel' => '', 'linkUrl' => '', 'endsAt' => '2026-10-04T02:00:00+00:00'];
		$notices = $this->createMock(PortalNoticeReader::class);
		$notices->expects($this->once())->method('active')->with('demo', 'portal')->willReturn([$notice]);

		$params = $this->controller(orgSlug: '', portal: ['slug' => 'demo'], notices: $notices)->site()->getParams();
		$this->assertSame([$notice], $params['portalConfig']['portalNotices']);

		$none = $this->createMock(PortalNoticeReader::class);
		$none->expects($this->never())->method('active');
		$params = $this->controller(orgSlug: '', notices: $none)->site()->getParams();
		$this->assertSame([], $params['portalConfig']['portalNotices']);

	}//end testSiteCarriesTheSignedInNotices()


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
	/**
	 * The site is never framed, except by its own origin for a request the
	 * accessibility measurement may make (site-accessibility-statement
	 * REQ-SAS-001).
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function testOnlyTheMeasurementMayFrameTheSiteAndOnlyFromItsOwnOrigin(): void {
		$policy = $this->controller(orgSlug: '')->site()->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("frame-ancestors 'none'", $policy);

		$this->framing = $this->getMockBuilder(AccessibilityFraming::class)->disableOriginalConstructor()->onlyMethods(['allowsSelf'])->getMock();
		$this->framing->method('allowsSelf')->willReturn(true);
		$policy = $this->controller(orgSlug: '')->site()->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("frame-ancestors 'self'", $policy);
		$this->assertStringNotContainsString('frame-ancestors *', $policy);

	}//end testOnlyTheMeasurementMayFrameTheSiteAndOnlyFromItsOwnOrigin()

	/**
	 * The real framing rule with nobody allowed to measure.
	 *
	 * @return AccessibilityFraming
	 */
	private function noFraming(): AccessibilityFraming {
		$framing = $this->getMockBuilder(AccessibilityFraming::class)->disableOriginalConstructor()->onlyMethods(['allowsSelf'])->getMock();
		$framing->method('allowsSelf')->willReturn(false);

		return $framing;
	}//end noFraming()

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
	 * REQ-PTB-002: a themed portal's token overrides reach the template as
	 * filtered CSS; an unthemed portal gets none, whatever it declares.
	 *
	 * @return void
	 */
	public function testSiteCarriesTheFilteredTokenOverridesOfAThemedPortalOnly(): void {
		$tokens = ['--nldesign-header-link-color' => '#f36c21', '--evil-x' => 'red', '--nldesign-bad' => 'url(x)'];

		$themed = $this->controller(orgSlug: '', portal: ['theme' => 'vng', 'tokens' => $tokens], themeStylesheet: 'themes/vng');
		$this->assertSame(':root{--nldesign-header-link-color:#f36c21}', $themed->site()->getParams()['themeTokenCss']);

		$unthemed = $this->controller(orgSlug: '', portal: ['theme' => 'vng', 'tokens' => $tokens], themeStylesheet: null);
		$this->assertSame('', $unthemed->site()->getParams()['themeTokenCss']);
	}//end testSiteCarriesTheFilteredTokenOverridesOfAThemedPortalOnly()


	/**
	 * site-links-the-theme-bridge: a themed portal gets the bridge, an
	 * unthemed one does not, although the theme app ships it in both cases.
	 * The bridge carries fallbacks; linked without a set it would restyle a
	 * page that must render unstyled.
	 *
	 * @return void
	 */
	public function testTheBridgeTravelsOnlyWithAResolvedSet(): void {
		$themed = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'denhaag'],
			themeStylesheet: 'tokens/denhaag'
		);
		// No logo file in this fixture, so no light logo or emblem either
		// (site-chrome-follows-the-design).
		$this->assertSame(['bridge' => 'public-bridge', 'fonts' => 'fonts', 'logoInverse' => '', 'emblem' => '', 'emblemGrey' => ''], $themed->site()->getParams()['themeAppSheets']);

		$unthemed = $this->controller(
			orgSlug: '',
			portal: ['theme' => 'nosuchset'],
			themeStylesheet: null
		);
		$this->assertSame(['bridge' => '', 'fonts' => '', 'logoInverse' => '', 'emblem' => '', 'emblemGrey' => ''], $unthemed->site()->getParams()['themeAppSheets']);
	}//end testTheBridgeTravelsOnlyWithAResolvedSet()

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
	 * The shell hands the renderer the slug it resolved, so first-party
	 * campaign capture can key its storage at boot, synchronously.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-utm-capture-is-first-party-portal-scoped-and-honest-about-being-advisory
	 */
	public function testSiteCarriesTheResolvedPortalSlug(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['slug' => 'open-tilburg', 'theme' => 'vng'],
			themeStylesheet: 'themes/vng',
			nldsStylesheet: 'themes/vng-tokens'
		);

		$params = $controller->site()->getParams();

		$this->assertSame(expected: 'open-tilburg', actual: $params['portalConfig']['resolvedPortal']);

	}//end testSiteCarriesTheResolvedPortalSlug()


	/**
	 * The site boots with the same ways in as `/portal`: the dev login only
	 * where the server accepts it, the silent sign-in provider, and the
	 * organisation and audience a login starts with.
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function testSiteCarriesTheSigninSettings(): void {
		$controller = $this->controller(
			orgSlug: '',
			resolved: ['devLogin' => true, 'silentSignIn' => 'digid', 'signinOrganisation' => 'school-org', 'audience' => 'client'],
			portal: ['slug' => 'wilgenboom', 'organisation' => 'school-org']
		);

		$signin = $controller->site()->getParams()['portalConfig']['signin'];

		$this->assertSame(
			expected: ['devLogin' => true, 'silentSignIn' => 'digid', 'signinOrganisation' => 'school-org', 'audience' => 'client', 'waysIn' => [], 'exampleResident' => '', 'exampleResidentWayIn' => ''],
			actual: $signin
		);

		$closed = $this->controller(orgSlug: '')->site()->getParams()['portalConfig']['signin'];
		$this->assertFalse($closed['devLogin']);

	}//end testSiteCarriesTheSigninSettings()


	/**
	 * The site's sign-in screen learns which ways in a portal opens from the
	 * same runtime config as `/portal`, and gets none when it names none.
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
	 */
	public function testSiteCarriesTheWaysIn(): void {
		$ways = ['register' => true, 'reference' => false, 'emailSignIn' => 'E-mail', 'referenceCaseTypes' => []];
		$controller = $this->controller(
			orgSlug: '',
			resolved: ['waysIn' => $ways],
			portal: ['slug' => 'wilgenboom', 'organisation' => 'school-org']
		);

		$this->assertSame(expected: $ways, actual: $controller->site()->getParams()['portalConfig']['signin']['waysIn']);

		$none = $this->controller(orgSlug: '')->site()->getParams()['portalConfig']['signin'];
		$this->assertSame(expected: [], actual: $none['waysIn']);

	}//end testSiteCarriesTheWaysIn()


	/**
	 * No resolved portal, or a resolver that throws, gives '' and still
	 * renders: the capture then keys by the explicit slug or not at all.
	 */
	public function testSiteCarriesNoResolvedSlugWhenResolutionFails(): void {
		$throwing = $this->controller(orgSlug: '', portalResolverThrows: true);
		$this->assertSame(expected: '', actual: $throwing->site()->getParams()['portalConfig']['resolvedPortal']);

		$none = $this->controller(orgSlug: '');
		$this->assertSame(expected: '', actual: $none->site()->getParams()['portalConfig']['resolvedPortal']);

	}//end testSiteCarriesNoResolvedSlugWhenResolutionFails()


	/**
	 * The shell titles the document with the PORTAL's name, before boot.
	 *
	 * The template has carried the slot since it took over the document; the
	 * controller never filled it, so a themed municipal portal served a tab
	 * reading "Portaal" until the bundle had fetched the site.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function testSiteCarriesTheResolvedPortalTitle(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['slug' => 'open-tilburg', 'title' => 'Gemeente Tilburg', 'theme' => 'vng'],
			themeStylesheet: 'themes/vng',
			nldsStylesheet: 'themes/vng-tokens'
		);

		$params = $controller->site()->getParams();

		$this->assertSame(expected: 'Gemeente Tilburg', actual: $params['portalConfig']['title']);

	}//end testSiteCarriesTheResolvedPortalTitle()


	/**
	 * Every unresolved shape answers '' — never a title borrowed from
	 * whichever portal happened to be first. The template turns '' into its
	 * own neutral fallback, so the page still renders.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function testSiteCarriesNoTitleWhenResolutionFails(): void {
		$throwing = $this->controller(orgSlug: '', portalResolverThrows: true);
		$this->assertSame(expected: '', actual: $throwing->site()->getParams()['portalConfig']['title']);

		$none = $this->controller(orgSlug: '');
		$this->assertSame(expected: '', actual: $none->site()->getParams()['portalConfig']['title']);

		$untitled = $this->controller(orgSlug: '', portal: ['slug' => 'open-tilburg']);
		$this->assertSame(expected: '', actual: $untitled->site()->getParams()['portalConfig']['title']);

	}//end testSiteCarriesNoTitleWhenResolutionFails()


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
	 * site-matches-the-zuiddrecht-boards: the document language follows the
	 * portal. A portal that declares only `nl` serves `nl` to a browser that
	 * asks for English; a portal that declares `en` as well serves the English
	 * the browser asked for; a portal that declares nothing serves what the
	 * browser asked for, as before.
	 *
	 * @return void
	 */
	public function testSiteLocaleFollowsThePortalsDeclaredLocales(): void {
		$dutchOnly = $this->controller(orgSlug: '', portal: ['slug' => 'zuiddrecht', 'locales' => ['nl']], acceptLanguage: 'en-US,en;q=0.9');
		$this->assertSame(expected: 'nl', actual: $dutchOnly->site()->getParams()['locale']);

		$both = $this->controller(orgSlug: '', portal: ['slug' => 'zuiddrecht', 'locales' => ['nl', 'en']], acceptLanguage: 'en-US,en;q=0.9');
		$this->assertSame(expected: 'en-US', actual: $both->site()->getParams()['locale']);

		$undeclared = $this->controller(orgSlug: '', portal: ['slug' => 'open-tilburg'], acceptLanguage: 'en-US,en;q=0.9');
		$this->assertSame(expected: 'en-US', actual: $undeclared->site()->getParams()['locale']);

	}//end testSiteLocaleFollowsThePortalsDeclaredLocales()

	/**
	 * site-page-seo-history-and-media REQ-SPH-002: the served head carries the
	 * page's search title, description and robots, without JavaScript.
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function testSiteServesThePagesHead(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['slug' => 'gemeente', 'title' => 'Gemeente Voorbeeld'],
			page: ['title' => 'Afval', 'summary' => 'Samenvatting', 'seo' => ['title' => 'Afval en recycling', 'description' => 'Opening hours of the town hall', 'noindex' => false, 'image' => '']],
			route: '/afval'
		);

		$head = $controller->site()->getParams()['head'];

		$this->assertSame('Afval en recycling - Gemeente Voorbeeld', $head['title']);
		$this->assertSame('Opening hours of the town hall', $head['description']);
		$this->assertSame('index, follow', $head['robots']);
		$this->assertStringContainsString('route=%2Fafval', $head['canonical']);

	}//end testSiteServesThePagesHead()


	/**
	 * A draft or an unknown route lends nothing: the portal's name and noindex.
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function testSiteServesNoindexWhenNoPageIsPublishedThere(): void {
		$controller = $this->controller(orgSlug: '', portal: ['slug' => 'gemeente', 'title' => 'Gemeente Voorbeeld'], page: null, route: '/concept');

		$head = $controller->site()->getParams()['head'];

		$this->assertSame('Gemeente Voorbeeld', $head['title']);
		$this->assertSame('noindex', $head['robots']);
		$this->assertSame('', $head['description']);

	}//end testSiteServesNoindexWhenNoPageIsPublishedThere()


	/**
	 * Build a controller.
	 *
	 * @param string      $orgSlug             The `org` request param.
	 * @param array       $resolved            Overrides for the resolved org config.
	 * @param array|null  $portal              The portal the portal resolver returns.
	 * @param string|null $themeStylesheet     What `stylesheetFor()` returns.
	 * @param string|null $nldsStylesheet      What `nldsStylesheetFor()` returns.
	 * @param bool        $portalResolverThrows Whether the portal resolver throws.
	 * @param string|null $logoFile            What `logoFileFor()` returns.
	 * @param string|null $themeAppId          What `themeAppId()` returns.
	 * @param array|null  $page                The page the CMS reader answers.
	 * @param string      $route               The `route` request param.
	 * @param string      $requestUri          The request URI, with its query.
	 * @param string      $portalParam         The `portal` request param.
	 * @param array|null  $byOrganisation      Organisation to the portal it resolves to.
	 * @param bool        $byOrganisationThrows Whether that lookup throws.
	 * @param PortalNoticeReader|null $notices The notice reader.
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
		?string $themeAppId = 'thematiq',
		?array $page = null,
		string $route = '',
		string $requestUri = '/index.php/apps/portaliq/portal',
		string $portalParam = '',
		?array $byOrganisation = null,
		bool $byOrganisationThrows = false,
		?PortalNoticeReader $notices = null,
		string $acceptLanguage = '',
		array $params = []
	): PortalPageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : match ($key) {
				'org' => $orgSlug,
				'portal' => ($portalParam !== '' ? $portalParam : $default),
				'route' => ($route !== '' ? $route : $default),
				default => $default,
			}
		);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => ($name === 'Accept-Language' ? $acceptLanguage : '')
		);
		$request->method('getRequestUri')->willReturn($requestUri);

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

		// WOO-566: index() no longer asks PortalOrganisationConfigService for a
		// tenant. The presentation comes from the portal object, through
		// PortalRuntimeConfigResolver; the organisation service keeps only the
		// OIDC half, which that resolver consults itself.
		$runtimeConfigResolver = $this->createMock(PortalRuntimeConfigResolver::class);
		$runtimeConfigResolver->method('runtimeConfigFor')->willReturn(array_merge($default, $resolved));
		$runtimeConfigResolver->method('resolvePortal')->willReturn($portal);
		$runtimeConfigResolver->method('themeStylesheetFor')->willReturn((string)$themeStylesheet);

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
		// A route-aware callback rather than one fixed literal, so
		// testManifestUrlNamesTheSameOrgThePageResolved() below can assert
		// which route + params the manifest link was actually built from,
		// without disturbing site()'s own use of the same mocked method
		// (still answers a `/api/content/site`-shaped path for that route).
		$urlGenerator->method('linkToRoute')
			->willReturnCallback(
				static fn (string $name, array $params = []): string => match ($name) {
					'portaliq.content.site' => '/index.php/apps/portaliq/api/content/site',
					'portaliq.portalPage.site' => '/index.php/apps/portaliq/site'.($params === [] ? '' : '?'.http_build_query($params)),
					'portaliq.portalPage.plain' => '/index.php/apps/portaliq/site/plain'.($params === [] ? '' : '?'.http_build_query($params)),
					default => ('/index.php/apps/portaliq/route/' . $name . '?' . http_build_query($params)),
				}
			);

		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $name, array $params = []): string => ('https://example.nl/index.php/apps/portaliq/site?' . http_build_query($params))
			);

		// The portal + theme resolvers decide which token stylesheets `site()`
		// emits. The DEFAULT is still "resolve nothing", so every pre-existing
		// assertion keeps measuring what it always did: an unthemed shell. The
		// parameters above let a single test opt into the resolved path or the
		// throwing one, which is what the callers below exercise.
		$portalResolver = $this->createMock(PortalResolver::class);
		// The rule itself is PortalResolverTest's; here only the wiring: the
		// controller hands the resolved portal and the visitor's language over.
		$portalResolver->method('localeFor')->willReturnCallback(
			fn (?array $portal, string $locale) => (($portal['locales'] ?? []) === ['nl'] ? 'nl' : $locale)
		);
		if ($portalResolverThrows === true) {
			$portalResolver->method('resolve')
				->willThrowException(new \RuntimeException('unknown host'));
		} else {
			$portalResolver->method('resolve')->willReturn($portal);
		}

		if ($byOrganisationThrows === true) {
			$portalResolver->method('resolveByOrganisation')
				->willThrowException(new \RuntimeException('register down'));
		} else {
			$portalResolver->method('resolveByOrganisation')->willReturnCallback(
				static fn (string $organisation): ?array => (($byOrganisation ?? [])[$organisation] ?? null)
			);
		}

		$themeResolver = $this->createMock(PortalThemeResolver::class);
		$themeResolver->method('stylesheetFor')->willReturn($themeStylesheet);
		$themeResolver->method('nldsStylesheetFor')->willReturn($nldsStylesheet);
		$themeResolver->method('shippedStylesheets')->willReturn(['bridge' => 'public-bridge', 'fonts' => 'fonts']);
		$themeResolver->method('logoFileFor')->willReturn($logoFile);
		// The id the theme app is installed under on this instance. The app is
		// mid-rename (`nldesign` -> `thematiq`), so the controller asks rather
		// than compiling one in — a URL built for an id nothing answers to is a
		// 404 logo on an otherwise intact page.
		$themeResolver->method('themeAppId')->willReturn($themeAppId);

		// The head of the page asked for (site-page-seo-history-and-media):
		// the real SiteHead over a reader that answers the one page given.
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page'])->getMock();
		$reader->method('page')->willReturn($page);

		return new PortalPageController(
			$request,
			$urlGenerator,
			new SiteShell(
				request: $request,
				portalResolver: $portalResolver,
				themeResolver: $themeResolver,
				urlGenerator: $urlGenerator,
				siteHead: new SiteHead($reader),
				notices: ($notices ?? $this->createMock(PortalNoticeReader::class)),
				configResolver: $runtimeConfigResolver,
				siteIcon: new SiteIcon(new MediaReferences($urlGenerator, $this->library()), $urlGenerator)
			),
			($this->framing ?? $this->noFraming()),
			PlainRendererFactory::make(reader: $reader, urlGenerator: $urlGenerator)
		);
	}//end controller()

	/**
	 * A media library holding one published image, `fav`, on `wilgenboom`.
	 *
	 * @return MediaLibraryReader
	 */
	private function library(): MediaLibraryReader {
		$library = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['item'])->getMock();
		$library->method('item')->willReturnCallback(
			static fn (string $portal, string $id) => ($portal === 'wilgenboom' && $id === 'fav') ? ['id' => 'fav', 'title' => 'Icoon', 'alt' => 'Icoon', 'kind' => 'image'] : null
		);

		return $library;
	}//end library()


	/**
	 * Every site page links a visitor without JavaScript to the plain page of
	 * the same route, search and portal included (site-honest-without-javascript
	 * REQ-SHJ-001), with the notice in the document language.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-every-site-page-says-so-when-javascript-is-off-req-shj-001
	 */
	public function testThePlainUrlCarriesTheRouteAndTheSearch(): void {
		$params = $this->controller(
			orgSlug: '',
			route: '/zoeken',
			portalParam: 'wilgenboom',
			params: ['_search' => 'afval', '_page' => '2']
		)->site()->getParams();

		$this->assertSame(
			'/index.php/apps/portaliq/site/plain?'.http_build_query(['route' => '/zoeken', 'portal' => 'wilgenboom', '_search' => 'afval', '_page' => '2']),
			$params['plainUrl']
		);
		$this->assertSame('This website uses JavaScript for the parts where you do something.', $params['noscript']['text']);
		$this->assertSame('Read the plain version of this page', $params['noscript']['linkText']);

		$bare = $this->controller(orgSlug: '')->site()->getParams();
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=%2F', $bare['plainUrl']);
	}//end testThePlainUrlCarriesTheRouteAndTheSearch()

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
	 * The portal's own favicon is the tab icon, ahead of the theme's logo,
	 * as the public media address (portal-identity-from-the-admin REQ-PIA-002).
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
	 */
	public function testSiteEmitsThePortalsFaviconAsTheTabIcon(): void {
		$controller = $this->controller(
			orgSlug: '',
			portal: ['slug' => 'wilgenboom', 'theme' => 'opencatalogi', 'favicon' => 'media:fav'],
			themeStylesheet: 'tokens/opencatalogi',
			logoFile: 'img/logos/opencatalogi.svg'
		);

		$params = $controller->site()->getParams();

		$this->assertStringContainsString('fav', $params['siteIcon']);
		$this->assertNotSame($params['themeLogoUrl'], $params['siteIcon']);
	}//end testSiteEmitsThePortalsFaviconAsTheTabIcon()


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
