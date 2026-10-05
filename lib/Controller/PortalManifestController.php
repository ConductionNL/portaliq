<?php

/**
 * Portaliq Portal Manifest Controller
 *
 * The two static assets that make the portal SPA installable
 * (parent-pwa-installability, learniq round-1 finding 10.3): a web app
 * manifest naming whichever portal the visitor is actually looking at, and a
 * service worker scoped to cache the app's own shell — never its API.
 *
 * Both routes are `#[PublicPage]`: a visitor installing the app has no
 * session yet, and neither response carries anything more sensitive than the
 * organisation's own already-public name and its own already-public static
 * assets (design.md D-2).
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
 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the manifest and the service worker.
 *
 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md
 */
class PortalManifestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRuntimeConfigResolver $configResolver Resolves the serving
	 *                                                    portal, the SAME way
	 *                                                    PortalPageController
	 *                                                    does, so the manifest
	 *                                                    names the portal
	 *                                                    actually being
	 *                                                    installed.
	 * @param IURLGenerator $urlGenerator Builds the icon and start_url links.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalRuntimeConfigResolver $configResolver,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The web app manifest for the portal named by `?org=`/`?portal=`.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-the-portal-serves-a-web-app-manifest-naming-the-resolved-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function manifest(): DataDisplayResponse {
		$orgValue = (string)$this->request->getParam('org', '');
		$portalSlug = (string)$this->request->getParam('portal', '');

		$portal = $this->configResolver->resolvePortal(request: $this->request, portalSlug: $portalSlug, orgValue: $orgValue);
		$runtimeConfig = $this->configResolver->runtimeConfigFor(portal: $portal, orgValue: $orgValue, locale: 'nl');

		$name = (string)($runtimeConfig['organisationName'] ?? '');
		if ($name === '') {
			$name = 'Portaliq';
		}

		$manifest = [
			'name' => $name,
			'short_name' => $this->shortName(name: $name),
			'start_url' => $this->startUrl(orgValue: $orgValue, portalSlug: $portalSlug),
			// The installed app stays on the site: a link it follows out of
			// `/site` opens in the browser instead of inside the app window.
			'scope' => $this->urlGenerator->linkToRoute('portaliq.portalPage.site'),
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#ffffff',
			'icons' => [
				[
					'src' => $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app.svg'),
					'sizes' => 'any',
					'type' => 'image/svg+xml',
				],
			],
		];

		$response = new DataDisplayResponse(
			(string)json_encode($manifest, JSON_UNESCAPED_SLASHES),
			200,
			['Content-Type' => 'application/manifest+json']
		);

		return $response;
	}//end manifest()

	/**
	 * The service worker script, scoped to the whole app path.
	 *
	 * @return DataDisplayResponse
	 *
	 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-the-service-worker-caches-the-app-shell-and-never-the-api
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function serviceWorker(): DataDisplayResponse {
		$path = $this->serviceWorkerSourcePath();
		$source = '';
		if (is_readable($path) === true) {
			$source = (string)file_get_contents($path);
		}

		$response = new DataDisplayResponse(
			$source,
			200,
			[
				'Content-Type' => 'application/javascript',
				// Widens the worker's control beyond its own serving path,
				// so it can control /site as well as /portal (design.md D-3).
				// This widens WHAT it may control, never the fetch handler's
				// own cache-vs-network decision (design.md D-1).
				'Service-Worker-Allowed' => $this->serviceWorkerScope(),
			]
		);

		// A worker's own fetch() follows the policy its script is served
		// with. Nextcloud's empty default has no connect-src, so it falls
		// back to default-src 'none' and every fetch of the worker failed:
		// returning visitors got net::ERR_FAILED on /site. Same origin only,
		// nothing wider; every other directive stays at the empty default.
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedConnectDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}//end serviceWorker()

	/**
	 * The widest scope the worker may be registered with: the app's route
	 * root, as the browser asked for the worker.
	 *
	 * The site registers the worker with scope `<route root>/`, worked out
	 * from the worker's own address (src/site/lib/pwa.js). The header must
	 * name the same path or the browser refuses the registration. It is
	 * read off the request because only the request knows how the browser
	 * reached the app: with or without `index.php`, under a web root, and
	 * at `/apps/<id>/` whether the app is installed in `apps/` or in
	 * `custom_apps/`. The app's web path (`linkTo`) is where its FILES are
	 * served, `/custom_apps/<id>/` for an app installed there, and no route
	 * lives under it, so the header named a path no page is on.
	 *
	 * @return string The scope, ending in a slash.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
	 */
	private function serviceWorkerScope(): string {
		$suffix = 'portal/sw.js';
		$path = parse_url($this->request->getRequestUri(), PHP_URL_PATH);
		if (is_string($path) === false || str_ends_with($path, '/' . $suffix) === false) {
			// Not reached through its own route (a test, an internal call):
			// the route the URL generator builds is the same address.
			$path = (string)parse_url(
				$this->urlGenerator->linkToRoute(Application::APP_ID . '.portalManifest.serviceWorker'),
				PHP_URL_PATH
			);
		}

		if (str_ends_with($path, '/' . $suffix) === false) {
			return '/apps/' . Application::APP_ID . '/';
		}

		return substr($path, 0, (strlen($path) - strlen($suffix)));
	}//end serviceWorkerScope()

	/**
	 * The service worker's path on disk. Not a webpack entry — `/js/` is
	 * entirely gitignored build output, so a hand-written service worker
	 * cannot live there (design.md Trade-offs). It lives in `src/shared/`,
	 * where it moved from the retired React portal's sources (REQ-SRP-045).
	 *
	 * A release package carries no `src/` (the shared release workflow
	 * excludes it), so the site build also copies the file, unchanged, to
	 * `js/portaliq-site-sw.js` (webpack.site.js), and that copy is served when
	 * the source is absent. The source wins when both exist, so a checkout
	 * never serves a stale build.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
	 */
	private function serviceWorkerSourcePath(): string {
		$appRoot = dirname(__DIR__, 2);
		$source = $appRoot . '/src/shared/serviceWorker.js';
		if (is_readable($source) === true) {
			return $source;
		}

		return $appRoot . '/js/portaliq-site-sw.js';
	}//end serviceWorkerSourcePath()

	/**
	 * A short_name under the platform's ~12-character guidance, derived from
	 * the resolved name rather than hardcoded, so it still says something
	 * when the organisation name is short enough to use as-is.
	 *
	 * @param string $name The resolved organisation name.
	 *
	 * @return string
	 */
	private function shortName(string $name): string {
		if ($name === '') {
			return 'Portaal';
		}

		if (mb_strlen($name) <= 12) {
			return $name;
		}

		return 'Portaal';
	}//end shortName()

	/**
	 * The URL the installed app opens to, carrying the same tenant reference
	 * the visitor is looking at right now.
	 *
	 * The site (`/site`), not the React portal: the portal is being retired
	 * in favour of the site (site-reaches-portal-parity REQ-SRP-044), so an
	 * app installed today must not open on an address that will only
	 * redirect.
	 *
	 * @param string $orgValue The `?org=` value, or ''.
	 * @param string $portalSlug The `?portal=` value, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-site-must-be-installable-req-srp-044
	 */
	private function startUrl(string $orgValue, string $portalSlug): string {
		$params = [];
		if ($portalSlug !== '') {
			$params['portal'] = $portalSlug;
		} elseif ($orgValue !== '') {
			$params['org'] = $orgValue;
		}

		return $this->urlGenerator->linkToRoute('portaliq.portalPage.site', $params);
	}//end startUrl()
}//end class
