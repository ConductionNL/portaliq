<?php

/**
 * Portaliq PortalPageController
 *
 * Serves the PUBLIC, white-label portal SPA (React + NL Design System) to the
 * two external audiences — clients and suppliers — who are NOT Nextcloud users.
 * The page renders with the public chrome (no Nextcloud navigation) and boots
 * the `portaliq-portal` bundle, which authenticates against the portal's own
 * auth edge (see the supplier-portal change) rather than a Nextcloud session.
 *
 * White-label resolution (portal-white-label-runtime-config): the visitor is
 * unauthenticated at this point (no bearer, no session claim to resolve a
 * tenant from), so the tenant is identified by a `?org={slug}` query
 * parameter (design.md — path-segment routing is a documented follow-up) and
 * resolved via {@see PortalOrganisationConfigService}. A missing/unknown
 * `org` renders the safe neutral default shell, never a 500 and never another
 * tenant's branding. The CSP `frame-ancestors` is built from the resolved
 * Organisation's configured allowed embed origins — `'none'` when empty,
 * NEVER the previous hard-coded `'*'`.
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
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
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
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param PortalOrganisationConfigService $orgResolver Resolves the tenant's
	 *                                                     white-label presentation.
	 * @param IURLGenerator $urlGenerator Builds the content API base handed to
	 *                                    the site renderer.
	 * @param PortalResolver $portalResolver Resolves the serving portal, so the
	 *                                       shell knows whose theme to load.
	 * @param PortalThemeResolver $themeResolver Maps that portal's theme
	 *                                           reference to a real themiq
	 *                                           token stylesheet.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalOrganisationConfigService $orgResolver,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolver $portalResolver,
		private readonly PortalThemeResolver $themeResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the public portal shell.
	 *
	 * The white-label runtime config (organisation name, theme, logo, IdP,
	 * feature flags) is resolved server-side from the `?org={slug}` query
	 * parameter and injected via IInitialStateService (see
	 * `templates/portal.php`; `src/portal/main.jsx` reads it back with
	 * `loadState('portaliq', 'runtimeConfig', ...)`). The React bundle takes
	 * over routing client-side; deep links are handled by catchAll(), which
	 * renders through this same method so every portal URL carries the
	 * resolved config.
	 *
	 * This is the one genuinely public HTML page in the fleet (ADR-081). The
	 * rate-limit ceiling below is generous on purpose: a citizen reloading a
	 * form must never be the thing that trips it.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T08
	 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.1
	 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#2.1
	 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#2.4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): TemplateResponse {
		$orgSlug = (string)$this->request->getParam('org', '');
		$locale = $this->resolveLocale();
		$runtimeConfig = $this->orgResolver->resolve(orgSlug: $orgSlug, locale: $locale);

		$response = new TemplateResponse(
			Application::APP_ID,
			'portal',
			['runtimeConfig' => $runtimeConfig],
			// BASE, NOT PUBLIC — same leak, same reasoning as site() below.
			// This route's own docblock calls it "the one genuinely public HTML
			// page in the fleet" and it is governed by
			// portal-white-label-runtime-config, which makes Nextcloud's header
			// bar sitting above it the plainest contradiction of its spec.
			// Fixed here as well as in site() because /portal is still served
			// while parity with /site is measured, and a leak on the route
			// being retired is still a leak today.
			TemplateResponse::RENDER_AS_BASE
		);

		// Per-tenant frame-ancestors (portal-white-label-runtime-config): the
		// portal carries a bearer token and renders authenticated actions, so
		// an unrestricted '*' is a clickjacking exposure. Default-deny; an
		// explicit tenant opts into embedding via its configured origins.
		// ContentSecurityPolicy() defaults `frame-ancestors` to 'self' — that
		// default must be cleared first, or an empty-origins tenant would
		// still (wrongly) allow same-origin framing instead of 'none'.
		$csp = new ContentSecurityPolicy();
		$csp->disallowFrameAncestorDomain('\'self\'');
		foreach ((array)($runtimeConfig['allowedEmbedOrigins'] ?? []) as $origin) {
			$csp->addAllowedFrameAncestorDomain((string)$origin);
		}

		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end index()

	/**
	 * Client-side-routed deep links (e.g. /portal/contracts/123) resolve to the
	 * same shell; the React router renders the correct view.
	 *
	 * @param string $path The deep-link path (unused server-side).
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T08
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $path is bound by the
	 * route definition; the SPA router consumes it client-side.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function catchAll(string $path = ''): TemplateResponse {
		return $this->index();
	}//end catchAll()

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
				// The ONLY things resolved server-side: which site, when the
				// caller named one, and which token stylesheet to load.
				// Host resolution — the normal path — needs nothing here.
				'portalConfig' => [
					'portal'  => (string)$this->request->getParam('portal', ''),
					'apiBase' => $this->urlGenerator->linkToRoute('portaliq.content.site'),
				],
				// THEME TOKENS ARE THE ONE THING THAT CANNOT WAIT FOR THE API.
				// Everything else this renderer shows is fetched after boot,
				// and that is the point of the headless split. Colours are
				// different in kind: resolving them client-side means the
				// first paint is unthemed and the page visibly repaints into
				// its brand a moment later. A consumer that is NOT this
				// renderer gets the same information — `theme` is on
				// `/api/content/site` — so this resolves no content the
				// contract withholds; it only decides which stylesheet tag to
				// emit.
				'themeStylesheet' => $this->siteThemeStylesheet(),
				// The NLDS token set this app ships for the serving portal's
				// theme, when it has one. Separate from the line above because
				// they answer different questions: that one is "which theme
				// app file", this one is "do we have the `--utrecht-*` tokens
				// the component CSS reads".
				'nldsStylesheet'  => $this->siteNldsStylesheet(),
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
			// skip target rather than the only one.
			TemplateResponse::RENDER_AS_BASE
		);

		// Deny framing unless the resolved site says otherwise. Same posture
		// as index(): clear the `'self'` default first, or a site with no
		// configured embedders still allows same-origin framing.
		$csp = new ContentSecurityPolicy();
		$csp->disallowFrameAncestorDomain('\'self\'');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end site()


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
				portalSlug: (string)$this->request->getParam('portal', '')
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
				portalSlug: (string)$this->request->getParam('portal', '')
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
