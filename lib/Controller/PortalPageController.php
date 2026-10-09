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
use OCA\Portaliq\Service\SiteShell;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\Cms\SiteIcon;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
	 * HTTP 302 Found: what `/portal` answers (REQ-SRP-048).
	 */
	private const STATUS_FOUND = 302;

	/**
	 * Builds what the site shell template renders.
	 *
	 * @var SiteShell
	 */
	private readonly SiteShell $shell;

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
	 * @param AccessibilityFraming $framing Whether the accessibility measurement may frame this request.
	 * @param SiteIcon $siteIcon The tab icon of the serving portal.
	 */
	public function __construct(
		IRequest $request,
		PortalRuntimeConfigResolver $configResolver,
		private readonly IURLGenerator $urlGenerator,
		PortalResolver $portalResolver,
		PortalThemeResolver $themeResolver,
		SiteHead $siteHead,
		PortalNoticeReader $notices,
		private readonly AccessibilityFraming $framing,
		SiteIcon $siteIcon,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->shell = new SiteShell(
			request: $request,
			portalResolver: $portalResolver,
			themeResolver: $themeResolver,
			urlGenerator: $urlGenerator,
			siteHead: $siteHead,
			notices: $notices,
			configResolver: $configResolver,
			siteIcon: $siteIcon
		);
	}//end __construct()

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
			$this->shell->templateParams(),
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
		// The one exception to "never framed" is the accessibility
		// measurement, which frames each page in the administrator's own
		// browser (site-accessibility-statement REQ-SAS-001): same origin
		// only, and only for a user who may measure. AccessibilityFraming
		// builds the whole policy.
		$response->setContentSecurityPolicy($this->framing->sitePolicy(request: $this->request));

		return $response;
	}//end site()


}//end class
