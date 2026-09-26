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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
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
				// so it can control /portal/... — see design.md D-3. This
				// widens WHAT it may control, never the fetch handler's own
				// cache-vs-network decision (design.md D-1).
				'Service-Worker-Allowed' => $this->urlGenerator->linkTo(Application::APP_ID, ''),
			]
		);

		return $response;
	}//end serviceWorker()

	/**
	 * The plain-JS source file's path on disk. Not a webpack entry — `/js/`
	 * is entirely gitignored build output, so a hand-written service worker
	 * cannot live there (design.md Trade-offs).
	 *
	 * @return string
	 */
	private function serviceWorkerSourcePath(): string {
		return dirname(__DIR__, 2) . '/src/portal/serviceWorker.js';
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
	 * @param string $orgValue The `?org=` value, or ''.
	 * @param string $portalSlug The `?portal=` value, or ''.
	 *
	 * @return string
	 */
	private function startUrl(string $orgValue, string $portalSlug): string {
		$params = [];
		if ($portalSlug !== '') {
			$params['portal'] = $portalSlug;
		} elseif ($orgValue !== '') {
			$params['org'] = $orgValue;
		}

		return $this->urlGenerator->linkToRoute('portaliq.portalPage.index', $params);
	}//end startUrl()
}//end class
