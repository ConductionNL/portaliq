<?php

/**
 * Portaliq Site Shell (portal-white-label-runtime-config)
 *
 * What the site shell template renders: the portal the request names, its theme, head and notices.
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
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Cms\SiteHead;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The values the site shell template is rendered with.
 *
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */
class SiteShell {
	/**
	 * The portal slug this request names, once read (see requestedPortalSlug()).
	 *
	 * @var string|null
	 */
	private ?string $requestedSlug = null;

	/**
	 * The theme of the portal the request names.
	 *
	 * @var SiteShellTheme|null
	 */
	private ?SiteShellTheme $themeOfRequest = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request        The request.
	 * @param PortalResolver              $portalResolver Resolves the serving portal.
	 * @param PortalThemeResolver         $themeResolver  Maps the portal's theme to a token stylesheet.
	 * @param IURLGenerator               $urlGenerator   Builds the content API base and the logo address.
	 * @param SiteHead                    $siteHead       The head of the page a site request asks for.
	 * @param PortalNoticeReader          $notices        The notices running on the signed-in surface now.
	 * @param PortalRuntimeConfigResolver $configResolver Resolves the runtime config built from the portal.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly PortalResolver $portalResolver,
		private readonly PortalThemeResolver $themeResolver,
		private readonly IURLGenerator $urlGenerator,
		private readonly SiteHead $siteHead,
		private readonly PortalNoticeReader $notices,
		private readonly PortalRuntimeConfigResolver $configResolver,
	) {
	}//end __construct()

	/**
	 * The template parameters of the site shell.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function templateParams(): array {
		$theme = $this->theme();

		return [
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
			'themeStylesheet' => $theme->stylesheet(),
			// The sets it extends, parent first, and the portal's own token
			// overrides (portal-theme-blocks-and-contributed-pages REQ-PTB-002, REQ-PTB-003).
			'themeParents' => $theme->parents(),
			'themeTokenCss' => $theme->tokenCss(),
			'themeLogoUrl' => $theme->logoUrl(),
			'themeAppSheets' => $theme->appSheets(),
			// The NLDS token set this app ships for the serving portal's
			// theme, when it has one. Separate from the line above because
			// they answer different questions: that one is "which theme
			// app file", this one is "do we have the `--utrecht-*` tokens
			// the component CSS reads".
			'nldsStylesheet'  => $theme->nldsStylesheet(),
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
		];
	}//end templateParams()

	/**
	 * The theme of the portal this request names.
	 *
	 * @return SiteShellTheme
	 */
	private function theme(): SiteShellTheme {
		if ($this->themeOfRequest === null) {
			$this->themeOfRequest = new SiteShellTheme(
				request: $this->request,
				portalResolver: $this->portalResolver,
				themeResolver: $this->themeResolver,
				urlGenerator: $this->urlGenerator,
				slug: $this->requestedPortalSlug()
			);
		}

		return $this->themeOfRequest;
	}//end theme()

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
	public function requestedPortalSlug(): string {
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
			$locale = 'nl';
		}

		return $this->localeThePortalServes(locale: $locale);
	}//end siteLocale()

	/**
	 * The visitor's language held to the portal's declared locales
	 * (PortalResolver::localeFor). A portal that cannot be resolved serves
	 * what the visitor asked for, as before.
	 *
	 * @param string $locale The visitor's language, never empty.
	 *
	 * @return string The language to serve.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	private function localeThePortalServes(string $locale): string {
		try {
			$portal = $this->portalResolver->resolve(request: $this->request, portalSlug: $this->requestedPortalSlug());
		} catch (\Throwable) {
			$portal = null;
		}

		return $this->portalResolver->localeFor(portal: $portal, locale: $locale);
	}//end localeThePortalServes()

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
	 *               waysIn: array<string, mixed>, exampleResident: string, exampleResidentWayIn: string}
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
			'devLogin'             => (($config['devLogin'] ?? false) === true),
			'silentSignIn'         => (string)($config['silentSignIn'] ?? ''),
			'signinOrganisation'   => (string)($config['signinOrganisation'] ?? ''),
			'audience'             => (string)($config['audience'] ?? ''),
			// The doors besides the sign-in buttons (identity-ways-in-screens T07).
			'waysIn'               => (array)($config['waysIn'] ?? []),
			// One click on a demo for the example resident (example-resident-demo-login).
			'exampleResident'      => (string)($config['exampleResident'] ?? ''),
			// The way in its install added, left out while the demo switch is off.
			'exampleResidentWayIn' => (string)($config['exampleResidentWayIn'] ?? ''),
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
