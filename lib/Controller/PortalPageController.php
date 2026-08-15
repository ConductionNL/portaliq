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
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalOrganisationConfigService $orgResolver,
		private readonly IURLGenerator $urlGenerator,
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
	// The portal shell — the one genuinely public HTML page in the fleet
	// (ADR-081). A generous ceiling: a citizen reloading a form must never be
	// the thing that trips it.
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): TemplateResponse {
		$orgSlug = (string)$this->request->getParam('org', '');
		$locale = $this->resolveLocale();
		$runtimeConfig = $this->orgResolver->resolve(orgSlug: $orgSlug, locale: $locale);

		$response = new TemplateResponse(
			Application::APP_ID,
			'portal',
			['runtimeConfig' => $runtimeConfig],
			TemplateResponse::RENDER_AS_PUBLIC
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
				'siteConfig' => [
					// The ONLY thing resolved server-side: which site, when the
					// caller named one. Host resolution — the normal path —
					// needs nothing here at all.
					'site'    => (string)$this->request->getParam('site', ''),
					'apiBase' => $this->urlGenerator->linkToRoute('portaliq.content.site'),
				],
			],
			TemplateResponse::RENDER_AS_PUBLIC
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
