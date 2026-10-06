<?php

/**
 * Portaliq PortalPageController
 *
 * Serves the PUBLIC, white-label site (`/site`, the Vue renderer with NL
 * Design System styling) to visitors and to the two signed-in audiences,
 * clients and suppliers, who are NOT Nextcloud users. The page boots the
 * `portaliq-site` bundle, which signs residents in against the portal's own
 * auth edge (`/portal/api/*`) rather than a Nextcloud session.
 *
 * `/portal` served the React portal until the site reached parity with it
 * (site-reaches-portal-parity). It now answers with a redirect to `/site`,
 * keeping its query string, so old links, installed apps and mails keep
 * working. The auth edge under `/portal/api/*`, the manifest, the service
 * worker and the embed frame keep their addresses.
 *
 * White-label resolution: the visitor is unauthenticated at this point, so
 * the portal is named by the request itself: `?portal={slug}`, `?org={value}`
 * as a strict alias, or the verified request host. A missing or unknown
 * portal renders the neutral shell, never a 500 and never another tenant's
 * branding.
 *
 * The CSP `frame-ancestors` is `'none'`: the site is never framed. The embed
 * frame (`PortalEmbedController`) has its own policy.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/supplier-portal/tasks.md#T08
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.1
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#2.1
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the public Portaliq SPA shell.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T08
 */
class PortalPageController extends Controller {
	/**
	 * The portal slug this request names, once read (see requestedPortalSlug()).
	 *
	 * @var string|null
	 */
	private ?string $requestedSlug = null;

	/**
	 * HTTP 302 Found: what `/portal` answers (REQ-SRP-048).
	 */
	private const STATUS_FOUND = 302;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param PortalRuntimeConfigResolver $configResolver Resolves the serving
	 *                                                    portal and the runtime
	 *                                                    config built from it.
	 * @param IURLGenerator $urlGenerator Builds the content API base handed to
	 *                                    the site renderer.
	 * @param PortalResolver $portalResolver Resolves the serving portal, so the
	 *                                       shell knows whose theme to load.
	 * @param PortalThemeResolver $themeResolver Maps that portal's theme
	 *                                           reference to a real themiq
	 *                                           token stylesheet.
	 * @param SiteHead $siteHead The head of the page a site request asks for.
	 * @param PortalNoticeReader $notices The notices running on the signed-in surface now.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalRuntimeConfigResolver $configResolver,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolver $portalResolver,
		private readonly PortalThemeResolver $themeResolver,
		private readonly SiteHead $siteHead,
		private readonly PortalNoticeReader $notices,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The portal this request names, read once: `?portal=`, else the one
	 * published portal of the organisation `?org=` names, else '' (the host
	 * decides). Mails built for an organisation carry `?org=`, and so did
	 * every `/portal` link, so the site reads it too (REQ-SRP-048).
	 *
	 * Only a resolved portal's slug comes out of `?org=`, never the raw value.
	 *
	 * @return string The slug, or ''.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	private function requestedPortalSlug(): string {
		if ($this->requestedSlug !== null) {
			return $this->requestedSlug;
		}

		$slug = trim((string)$this->request->getParam('portal', ''));
		$org = trim((string)$this->request->getParam('org', ''));
		if ($slug === '' && $org !== '') {
			try {
				$portal = $this->portalResolver->resolveByOrganisation(organisation: $org);
				$slug = (string)($portal['slug'] ?? '');
			} catch (\Throwable) {
				$slug = '';
			}
		}

		$this->requestedSlug = $slug;
		return $slug;
	}//end requestedPortalSlug()

	/**
	 * The retired React portal's address: a redirect to the site.
	 *
	 * `/portal` served the React portal until the Vue site reached parity
	 * with it (site-reaches-portal-parity). Old bookmarks, installed apps and
	 * mails still carry this address, so it answers 302 to the site with the
	 * same query string: `?portal=` and `?org=` keep naming the same portal,
	 * because `site()` reads both.
	 *
	 * The fragment (`#token=`, `#open=`, `#confirm-email=`, `#signin=failed`)
	 * never reaches the server, and it does not have to: a browser applies the
	 * original fragment to a redirect whose `Location` carries none
	 * (RFC 9110 section 10.2.2), so the site still reads it.
	 *
	 * @return RedirectResponse
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): RedirectResponse {
		$response = new RedirectResponse($this->siteAddress());
		// 302, not RedirectResponse's own 303: the address moved, the
		// request did not change meaning.
		$response->setStatus(self::STATUS_FOUND);
		return $response;
	}//end index()

	/**
	 * A deep link under the retired portal (`/portal/<anything>`) lands on the
	 * site with its query string. The React portal kept its screens in state,
	 * never in the path, so the path names nothing the site could open.
	 *
	 * @param string $path The deep-link path (unused).
	 *
	 * @return RedirectResponse
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $path is bound by the
	 * route definition and carries nothing the site can use.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function catchAll(string $path = ''): RedirectResponse {
		return $this->index();
	}//end catchAll()

	/**
	 * The site's address with this request's query string, unchanged.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	private function siteAddress(): string {
		$site = $this->urlGenerator->linkToRoute('portaliq.portalPage.site');
		$query = (string)parse_url($this->request->getRequestUri(), PHP_URL_QUERY);
		if ($query === '') {
			return $site;
		}

		return $site . '?' . $query;
	}//end siteAddress()

	/**
	 * The built-in SITE renderer shell (ADR-084).
	 *
	 * Deliberately thin. Unlike `index()`, this template resolves nothing
	 * server-side beyond an explicit site slug: title, theme, menus, pages and
	 * glossary all come from the PUBLIC content API at runtime, exactly as they
	 * do for a Docusaurus build. The moment this method starts resolving
	 * content, the built-in renderer has a privileged path no other consumer
	 * can use, and the CMS stops being headless (ADR-086 §1).
	 *
	 * Served alongside `/portal` while parity is measured — a comparison
	 * against a portal that has already been deleted is not a comparison.
	 *
	 * @return TemplateResponse The site shell.
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function site(): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'site',
			[
				// The ONLY things resolved server-side: which site, when the caller
				// named one (`?portal=` or `?org=`), and which token stylesheet to
				// load. Host resolution, the normal path, needs nothing here.
				'portalConfig' => [
					'portal'  => $this->requestedPortalSlug(),
					'apiBase' => $this->urlGenerator->linkToRoute('portaliq.content.site'),
					// The serving portal's slug, resolved the same way the theme
					// above is (host, or the named site). Content still comes from
					// the API; this only lets first-party campaign capture key its
					// storage by portal at boot, synchronously, instead of after
					// the site fetch, where a quick visitor lost the landing.
					'resolvedPortal' => $this->siteResolvedSlug(),
					// Title: see siteTitle(). Signed-in notices: see sitePortalNotices().
					'title' => $this->siteTitle(),
					'signin' => $this->siteSignin(),
					'portalNotices' => $this->sitePortalNotices(),
				],
				// THEME TOKENS ARE THE ONE THING THAT CANNOT WAIT FOR THE API.
				// Everything else this renderer shows is fetched after boot,
				// and that is the point of the headless split. Colours are
				// different in kind: resolving them client-side means the
				// first paint is unthemed and the page visibly repaints into
				// its brand a moment later. A consumer that is NOT this
				// renderer gets the same information — `theme` is on
				// `/api/content/site` — so this resolves no content the
				// contract withholds; it only decides which stylesheet tag to emit.
				'themeStylesheet' => $this->siteThemeStylesheet(),
				'themeLogoUrl' => $this->siteThemeLogoUrl(),
				'themeAppSheets' => $this->siteThemeAppSheets(),
				// The NLDS token set this app ships for the serving portal's
				// theme, when it has one. Separate from the line above because
				// they answer different questions: that one is "which theme
				// app file", this one is "do we have the `--utrecht-*` tokens
				// the component CSS reads".
				'nldsStylesheet'  => $this->siteNldsStylesheet(),
				// The standalone shell owns the whole document now, so it needs
				// the document language. Resolved from Accept-Language the same
				// way index() does — the visitor is unauthenticated here, so
				// there is no session locale to prefer.
				//
				// Never the empty string: this value becomes `<html lang="">`,
				// which is a WCAG failure and is exactly the shape a request
				// carrying no Accept-Language would otherwise produce.
				'locale'          => $this->siteLocale(),
				'head'            => $this->siteHead(),
			],
			// BASE, NOT PUBLIC — a white-label site may not wear Nextcloud's
			// chrome. `layout.public.php` emits `<header id="header">` with
			// `header-appname`, the Nextcloud logo and a `header-info` title,
			// and it is VISIBLE (measured 108x33 at the top of the viewport on
			// an anonymous load). A municipality's portal was rendering another
			// product's brand above its own, to visitors who never logged in.
			//
			// This is the same class of leak as the document title, and it hid
			// the same way: every check looked at the content area, where the
			// portal renders correctly, so nothing screenshot-shaped ever saw
			// the bar above it.
			//
			// `layout.base.php` emits no header at all — just `#content` —
			// while still emitting the CSS/script tags and initial state the
			// renderer boots from. The skip link is not lost either: the site
			// renders its OWN localised one ("Direct naar de inhoud"), so
			// dropping core's English duplicate removes a second, conflicting
			// BLANK — the template renders the WHOLE document.
			//
			// `RENDER_AS_BASE` was the previous answer and it was not enough:
			// even the barest Nextcloud layout ships `server.css` (587 rules)
			// and the instance theme chain, which kept the content column
			// inset (1235px at +50px against the reference's 1280 at 0) and
			// rendered bare `h1` in the platform's typeface. Those rules
			// outrank anything this app can scope, so the fix is to stop
			// loading them: see the long note at the top of templates/site.php.
			TemplateResponse::RENDER_AS_BLANK
		);

		// The NLDS component CSS requests its webfonts from Google's font
		// CDN. Nextcloud's default `font-src 'self' data:` blocks them, and a
		// blocked font is not a console curiosity here — it is the portal
		// rendering in a fallback face while every token says otherwise, which
		// is exactly the class of mismatch this whole change exists to remove.
		// Allowed narrowly: the font origin and its stylesheet host, nothing
		// else.
		//
		// Deny framing unless the resolved site says otherwise. Same posture
		// as index(): clear the `'self'` default first, or a site with no
		// configured embedders still allows same-origin framing.
		$csp = new ContentSecurityPolicy();
		$csp->disallowFrameAncestorDomain('\'self\'');
		$csp->addAllowedFontDomain('https://fonts.gstatic.com');
		$csp->addAllowedStyleDomain('https://fonts.googleapis.com');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end site()


	/**
	 * The document language for the standalone shell, never empty.
	 *
	 * `resolveLocale()` answers '' when the request carries no usable
	 * `Accept-Language`, which is the ordinary case for a bot, a curl, or a
	 * browser with the header stripped. Passing that through would emit
	 * `<html lang="">` — a WCAG 3.1.1 failure that no screenshot shows and no
	 * functional test notices, because the page otherwise renders perfectly.
	 *
	 * @return string A non-empty BCP-47 tag.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	private function siteLocale(): string {
		$locale = $this->resolveLocale();
		if ($locale === '') {
			return 'nl';
		}

		return $locale;
	}//end siteLocale()


	/**
	 * The document head for the route this request asks for
	 * (site-page-seo-history-and-media). Headless is kept: it is the same
	 * anonymous read the content API makes, so it resolves nothing a consumer
	 * of the API cannot read, and the renderer still fetches the page itself.
	 *
	 * @return array{title: string, description: string, robots: string, canonical: string, ogImage: string}
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	private function siteHead(): array {
		$portalSlug = $this->requestedPortalSlug();
		try {
			$portal = $this->portalResolver->resolve(request: $this->request, portalSlug: $portalSlug);
		} catch (\Throwable) {
			$portal = null;
		}

		$route = (string)$this->request->getParam('route', '/');
		$params = ['route' => $route];
		if ($portalSlug !== '') {
			$params['portal'] = $portalSlug;
		}

		return $this->siteHead->for(
			portal: $portal,
			route: $route,
			locale: $this->siteLocale(),
			canonical: $this->urlGenerator->linkToRouteAbsolute('portaliq.portalPage.site', $params)
		);
	}//end siteHead()


	/**
	 * The sign-in settings the site needs at boot, from the same resolver
	 * `/portal` uses, so the two surfaces offer the same ways in.
	 *
	 * @return array{devLogin: bool, silentSignIn: string, signinOrganisation: string, audience: string,
	 *               waysIn: array<string, mixed>, exampleResident: string}
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T07
	 */
	private function siteSignin(): array {
		$portal = $this->configResolver->resolvePortal(
			request: $this->request,
			portalSlug: $this->requestedPortalSlug(),
			orgValue: ''
		);
		$config = $this->configResolver->runtimeConfigFor(portal: $portal, orgValue: '', locale: $this->siteLocale());

		return [
			'devLogin'           => (($config['devLogin'] ?? false) === true),
			'silentSignIn'       => (string)($config['silentSignIn'] ?? ''),
			'signinOrganisation' => (string)($config['signinOrganisation'] ?? ''),
			'audience'           => (string)($config['audience'] ?? ''),
			// The doors besides the sign-in buttons (identity-ways-in-screens T07).
			'waysIn'             => (array)($config['waysIn'] ?? []),
			// One click on a demo for the example resident (example-resident-demo-login).
			'exampleResident'    => (string)($config['exampleResident'] ?? ''),
		];
	}//end siteSignin()


	/**
	 * The notices running now on the signed-in surface of the serving portal,
	 * or none when the request resolves no portal. The site shows them next
	 * to the public ones once a resident is signed in, as `/portal` did
	 * (operate-maintenance-notice, site-reaches-portal-parity REQ-SRP-010).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notices-must-show-above-every-page-req-srp-010
	 */
	private function sitePortalNotices(): array {
		$slug = $this->siteResolvedSlug();
		if ($slug === '') {
			return [];
		}

		try {
			return $this->notices->active(portal: $slug, surface: 'portal');
		} catch (\Throwable) {
			return [];
		}
	}//end sitePortalNotices()


	/**
	 * The serving portal's slug, or '' when the request resolves to none.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-utm-capture-is-first-party-portal-scoped-and-honest-about-being-advisory
	 */
	private function siteResolvedSlug(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->requestedPortalSlug()
			);
		} catch (\Throwable) {
			return '';
		}

		return (string)($portal['slug'] ?? '');
	}//end siteResolvedSlug()


	/**
	 * The serving portal's display title, or '' when the request resolves to
	 * no portal.
	 *
	 * Fails to the empty string on every miss — unknown host, unknown slug, a
	 * resolver that throws — and the template then renders its own neutral
	 * fallback. Never another portal's name: a tab reading "Gemeente Tilburg"
	 * on somebody else's site is a branding leak that looks entirely correct.
	 *
	 * @return string The portal title, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	private function siteTitle(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->requestedPortalSlug()
			);
		} catch (\Throwable) {
			return '';
		}

		if ($portal === null) {
			return '';
		}

		return (string)($portal['title'] ?? '');
	}//end siteTitle()


	/**
	 * The token stylesheet the serving portal's theme resolves to, or ''.
	 *
	 * Returns the empty string for every failure — unknown host, no theme,
	 * theme app absent, theme file missing. That is deliberate and it is the
	 * same answer in each case: the page renders UNSTYLED rather than in
	 * whichever brand happened to be first. A portal quietly wearing another
	 * municipality's colours looks correct in every screenshot; an unstyled
	 * one gets reported within the hour.
	 *
	 * @return string The stylesheet path relative to the theme app's css/, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function siteThemeStylesheet(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->requestedPortalSlug()
			);
		} catch (\Throwable) {
			return '';
		}

		if ($portal === null) {
			return '';
		}

		return (string)$this->themeResolver->stylesheetFor(
			theme: (string)($portal['theme'] ?? '')
		);
	}//end siteThemeStylesheet()


	/**
	 * The theme app's own stylesheets for the serving portal: its public
	 * bridge and its bundled faces, each '' when not shipped.
	 *
	 * Only with a resolved set: the bridge carries fallbacks, so linking it on
	 * an unthemed portal would quietly restyle a page that must render
	 * unstyled, and an unthemed page names no bundled family.
	 *
	 * @return array{bridge: string, fonts: string, logoInverse: string, emblem: string, emblemGrey: string} The stylesheets
	 *         (relative to the theme app's `css/`) and the logo variants (absolute).
	 *
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-faces-the-theme-app-bundles-req-stb-002
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	private function siteThemeAppSheets(): array {
		$none = ['bridge' => '', 'fonts' => '', 'logoInverse' => '', 'emblem' => '', 'emblemGrey' => ''];
		if ($this->siteThemeStylesheet() === '') {
			return $none;
		}

		try {
			$shipped = $this->themeResolver->shippedStylesheets();
			return [
				'bridge' => (string)($shipped['bridge'] ?? ''),
				'fonts'  => (string)($shipped['fonts'] ?? ''),
				// The set's light logo for the dark footer and its emblem for
				// a watermark, absolute, or '' (site-chrome-follows-the-design).
				'logoInverse' => $this->siteThemeLogoUrl(variant: 'dark'),
				'emblem'      => $this->siteThemeLogoUrl(variant: 'emblem'),
				// The emblem in grey, for a set whose watermark carries no
				// tint, or '' (example-site-zuiddrecht).
				'emblemGrey'  => $this->siteThemeLogoUrl(variant: 'emblem-grey'),
			];
		} catch (\Throwable) {
			return $none;
		}
	}//end siteThemeAppSheets()


	/**
	 * An ABSOLUTE URL for the serving portal's theme logo, or ''.
	 *
	 * Token sets declare `--nldesign-logo-url` as a path relative to the token
	 * file. That is correct there and wrong by the time it is used: the rule
	 * consuming the token lives in THIS app's bundled NL Design System CSS,
	 * and a browser resolves a relative `url()` inside a custom property
	 * against the stylesheet doing the consuming.
	 *
	 * Measured on the demo rig: the header requested
	 * `/custom_apps/portaliq/img/logos/opencatalogi.svg` — this app's
	 * directory — and the portal rendered with no logo, while every token
	 * involved held exactly the right value. Nothing about the tokens looked
	 * wrong, because nothing about them was.
	 *
	 * So the resolution happens here, where the theme app's real path is
	 * known, and the template emits the result after the token stylesheets.
	 *
	 * With a `$variant` it is that variant of the set's logo
	 * (site-chrome-follows-the-design): `dark`, the light logo for the dark
	 * footer band, or `emblem`, the mark for a watermark; '' when the set
	 * ships none.
	 *
	 * @param string $variant '' for the logo, else `dark`, `emblem` or `emblem-grey`.
	 *
	 * @return string An absolute URL, or '' when there is no logo to serve.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	private function siteThemeLogoUrl(string $variant=''): string {
		$stylesheet = $this->siteThemeStylesheet();
		if ($stylesheet === '') {
			return '';
		}

		// `tokens/<theme>` → `<theme>`.
		$theme = basename($stylesheet);
		if ($theme === '') {
			return '';
		}

		try {
			$relative = $this->themeResolver->logoFileFor(theme: $theme, variant: $variant);

			if ($relative === null) {
				return '';
			}

			// Link against the id this instance actually serves the theme app
			// under, not a compiled-in guess: the app is mid-rename from
			// `nldesign` to `thematiq`, and `linkTo()` will happily build a URL
			// for an id nothing answers to — a 404 logo on an otherwise intact
			// page.
			$themeApp = $this->themeResolver->themeAppId();
			if ($themeApp === null) {
				return '';
			}

			return $this->urlGenerator->linkTo($themeApp, $relative);
		} catch (\Throwable) {
			return '';
		}
	}//end siteThemeLogoUrl()


	/**
	 * The NLDS token stylesheet this app ships for the serving portal, or ''.
	 *
	 * Same fail-quiet posture as `siteThemeStylesheet()`: every failure — no
	 * portal, unknown theme, no token file for it — returns the empty string
	 * and the page renders with the component library's own defaults rather
	 * than another municipality's colours.
	 *
	 * @return string The stylesheet path relative to this app's `css/`, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function siteNldsStylesheet(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->requestedPortalSlug()
			);
		} catch (\Throwable) {
			return '';
		}

		if ($portal === null) {
			return '';
		}

		return (string)$this->themeResolver->nldsStylesheetFor(
			theme: (string)($portal['theme'] ?? '')
		);
	}//end siteNldsStylesheet()

	/**
	 * Resolve the visitor's locale from the `Accept-Language` header
	 * (portal-spa-i18n-locale-support) — the visitor is unauthenticated at
	 * this point, so there is no session/tenant locale to prefer yet. Only
	 * the first (highest-priority) language tag is read; normalisation to a
	 * supported locale (falling back to `nl`) happens in
	 * `PortalOrganisationConfigService`.
	 *
	 * @return string The raw first `Accept-Language` tag, or `''` when absent.
	 *
	 * @spec openspec/changes/portal-spa-i18n-locale-support/tasks.md#2.2
	 */
	private function resolveLocale(): string {
		$header = $this->request->getHeader('Accept-Language');
		if ($header === '') {
			return '';
		}

		$first = explode(',', $header)[0];
		$first = explode(';', $first)[0];

		return trim($first);
	}//end resolveLocale()
}//end class
