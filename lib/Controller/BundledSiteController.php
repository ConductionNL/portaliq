<?php

/**
 * Hosts a bundled single-page site as a Nextcloud public page. A bundled site
 * is a directory of build output under `build/`; this controller serves its
 * static assets and falls back to `index.html` for client-side routes
 * (ADR-046: the standalone-SPA pattern, served in-app).
 *
 * Which bundle a URL serves is configuration, not code: the route names the
 * bundle in its `defaults` (appinfo/routes.php), and BUNDLES lists the
 * directories this app ships. Today that is one, `build/woo/`, the Open Tilburg
 * publication site built with `PUBLIC_URL=/index.php/apps/portaliq/woo`
 * (decision 182: named by what it does, the procedure is configuration).
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCA\Portaliq\Service\RetiredBundleAddress;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves a bundled single-page site as an in-app public page.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-bundled-site-is-served-by-a-generic-host
 */
class BundledSiteController extends Controller {

	/**
	 * The bundle directories this app ships, relative to `build/`. A
	 * route may only name one of these: `bundle` arrives as a request
	 * parameter, so it is checked against this list and never used as a path.
	 *
	 * @var string[]
	 */
	private const BUNDLES = ['woo'];

	/**
	 * The app setting, per bundle, naming the portal whose authored site now
	 * answers on the bundle's paths (`bundled_site_portal_woo`). Empty: the
	 * bundle itself still serves them.
	 */
	public const PORTAL_SETTING_PREFIX = 'bundled_site_portal_';

	/**
	 * HTTP 302 Found: what a bundle path answers once its portal is authored.
	 */
	private const STATUS_FOUND = 302;

	/**
	 * Absolute path to the directory the bundles live in, `build/` in the app root.
	 *
	 * @var string
	 */
	private string $bundlesRoot;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig|null $appConfig Names the portal a bundle's paths moved to; null keeps the bundle serving.
	 * @param IURLGenerator|null $urlGenerator Builds the authored site's address.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ?IAppConfig $appConfig = null,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
		parent::__construct(appName: $appName, request: $request);

		// Initialise to the unresolved path, then override when `realpath()`
		// can canonicalise it.
		$this->bundlesRoot = __DIR__ . '/../../build';
		$resolved = realpath($this->bundlesRoot);
		if ($resolved !== false) {
			$this->bundlesRoot = $resolved;
		}
	}//end __construct()

	/**
	 * Serve the entry of a bundled site (`/woo` serves the `woo` bundle).
	 *
	 * A bundled site is a public surface, so the rate limit below is a
	 * runaway ceiling, not a gate.
	 *
	 * @param string $bundle The bundle the route names in its defaults.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-bundled-site-is-served-by-a-generic-host
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function serve(string $bundle = ''): Response {
		return $this->render(bundle: $bundle, path: '');
	}//end serve()

	/**
	 * Serve a bundled asset, or fall back to the entry for client routes
	 * (`/woo/{path}` serves from the `woo` bundle).
	 *
	 * @param string $bundle The bundle the route names in its defaults.
	 * @param string $path   The requested sub-path.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-bundled-site-is-served-by-a-generic-host
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function servePath(string $bundle = '', string $path = ''): Response {
		return $this->render(bundle: $bundle, path: $path);
	}//end servePath()

	/**
	 * Resolve the request path to a bundled file (path-traversal safe) and
	 * return it, or the SPA `index.html` when the path is empty / not a real
	 * bundled file (react-router client route).
	 *
	 * A bundle this app does not ship answers 404: the name is matched
	 * against BUNDLES and never joined into a path unchecked.
	 *
	 * @param string $bundle The bundle to serve from.
	 * @param string $path   The requested sub-path.
	 *
	 * @return Response
	 */
	private function render(string $bundle, string $path): Response {
		$moved = $this->authoredSiteAddress(bundle: $bundle, path: $path);
		if ($moved !== null) {
			$response = new RedirectResponse($moved);
			// 302, not RedirectResponse's own 303: the address moved, the
			// request did not change meaning.
			$response->setStatus(self::STATUS_FOUND);
			return $response;
		}

		$root = $this->bundleRoot(bundle: $bundle);
		if ($root === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		}

		$resolved = $this->bundledFile(root: $root, path: $path);

		$content = $this->contentOf(resolved: $resolved);
		if ($content === false) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		}

		return $this->fileResponse(resolved: $resolved, content: $content);
	}//end render()

	/**
	 * Where the authored site answers this bundle path, or null while the
	 * bundle still serves it.
	 *
	 * An administrator moves a bundle's paths to an authored portal by naming
	 * that portal in `bundled_site_portal_<bundle>`; until then nothing changes
	 * for the visitors of the bundle. Once set, every path, the ones the bundle
	 * routed and the ones it did not, redirects to the site route
	 * RetiredBundleAddress gives it, keeping the query string and naming the
	 * portal when the query does not already.
	 *
	 * @param string $bundle The bundle name from the route defaults.
	 * @param string $path   The path under the bundle.
	 *
	 * @return string|null The address, or null.
	 *
	 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
	 */
	private function authoredSiteAddress(string $bundle, string $path): ?string {
		if ($this->appConfig === null
			|| $this->urlGenerator === null
			|| in_array($bundle, self::BUNDLES, true) === false
		) {
			return null;
		}

		$portal = trim($this->appConfig->getValueString($this->appName, self::PORTAL_SETTING_PREFIX . $bundle, ''));
		if ($portal === '') {
			return null;
		}

		$route = (new RetiredBundleAddress())->siteRoute(path: $path);
		$address = $this->urlGenerator->linkToRoute('portaliq.portalPage.site');
		if ($route !== RetiredBundleAddress::HOME) {
			$address = $this->urlGenerator->linkToRoute(
				'portaliq.portalPage.sitePath',
				['path' => ltrim($route, '/')]
			);
		}

		$query = [];
		parse_str((string)parse_url($this->request->getRequestUri(), PHP_URL_QUERY), $query);
		if (isset($query['portal']) === false) {
			$query['portal'] = $portal;
		}

		return $address . '?' . http_build_query($query);
	}//end authoredSiteAddress()

	/**
	 * The bytes of a resolved bundled file, or false when it is not a file or
	 * cannot be read.
	 *
	 * @param string $resolved The resolved file.
	 *
	 * @return string|false
	 */
	private function contentOf(string $resolved): string|false {
		$content = false;
		if (is_file($resolved) === true) {
			$content = file_get_contents($resolved);
		}

		return $content;
	}//end contentOf()

	/**
	 * The response serving a bundled file: hash-named assets cached, the SPA
	 * index and the runtime config never cached and under the SPA's CSP.
	 *
	 * @param string $resolved The resolved file.
	 * @param string $content  Its bytes.
	 *
	 * @return Response
	 */
	private function fileResponse(string $resolved, string $content): Response {
		$isIndex = (substr($resolved, -10) === 'index.html');
		$response = new DataDisplayResponse($content, Http::STATUS_OK, ['Content-Type' => $this->mimeFor(file: $resolved)]);

		// Runtime-config.js must never be cached — it is redeployed per instance.
		$noCache = ($isIndex === true || substr($resolved, -17) === 'runtime-config.js');
		if ($noCache === false) {
			// Hash-named static assets are immutable.
			$response->cacheFor(86400);
			return $response;
		}

		$this->applySpaCsp(response: $response);

		return $response;
	}//end fileResponse()

	/**
	 * The file a request path names inside a bundle, or the bundle's
	 * `index.html` when the path is empty, leaves the bundle or names no real
	 * file (a client-side route).
	 *
	 * @param string $root The bundle's directory.
	 * @param string $path The requested sub-path.
	 *
	 * @return string The absolute file to serve.
	 */
	private function bundledFile(string $root, string $path): string {
		$relative = ltrim(str_replace('\\', '/', $path), '/');
		$resolved = realpath($root . '/' . $relative);

		if ($relative === ''
			|| $resolved === false
			|| strncmp($resolved, $root . '/', (strlen($root) + 1)) !== 0
			|| is_file($resolved) === false
		) {
			return $root . '/index.html';
		}

		return $resolved;
	}//end bundledFile()

	/**
	 * The directory a bundle is served from, or null for a name that is not a
	 * bundle this app ships.
	 *
	 * @param string $bundle The bundle name the route gave.
	 *
	 * @return string|null The absolute directory, canonical when it exists.
	 */
	private function bundleRoot(string $bundle): ?string {
		if (in_array($bundle, self::BUNDLES, true) === false) {
			return null;
		}

		$root = $this->bundlesRoot . '/' . $bundle;
		$canonical = realpath($root);
		if ($canonical !== false) {
			return $canonical;
		}

		return $root;
	}//end bundleRoot()

	/**
	 * Relax the default (`default-src 'none'`) CSP for the bundled SPA entry.
	 *
	 * The bundled SPA loads its own same-origin scripts/styles/fonts and boots
	 * via an inline script. The policy starts EMPTY (no nonce / no
	 * strict-dynamic that would make the browser ignore 'self') and allows
	 * exactly what the bundled same-origin SPA needs — everything stays
	 * same-origin ('self') + data:.
	 *
	 * @param DataDisplayResponse $response The response to attach the policy to.
	 *
	 * @return void
	 */
	private function applySpaCsp(DataDisplayResponse $response): void {
		$csp = new EmptyContentSecurityPolicy();
		$csp->allowInlineStyle(true);
		$csp->addAllowedScriptDomain("'self'");
		$csp->addAllowedStyleDomain("'self'");
		$csp->addAllowedFontDomain("'self'");
		$csp->addAllowedFontDomain('data:');
		$csp->addAllowedFontDomain('https://fonts.gstatic.com');
		$csp->addAllowedImageDomain("'self'");
		$csp->addAllowedImageDomain('data:');
		$csp->addAllowedConnectDomain("'self'");
		$response->setContentSecurityPolicy($csp);
	}//end applySpaCsp()

	/**
	 * Map a file extension to a Content-Type.
	 *
	 * @param string $file The file path.
	 *
	 * @return string
	 */
	private function mimeFor(string $file): string {
		$map = [
			'html' => 'text/html; charset=utf-8',
			'js' => 'application/javascript',
			'css' => 'text/css',
			'json' => 'application/json',
			'svg' => 'image/svg+xml',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'ico' => 'image/x-icon',
			'webp' => 'image/webp',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf' => 'font/ttf',
			'otf' => 'font/otf',
			'txt' => 'text/plain; charset=utf-8',
		];

		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		return ($map[$ext] ?? 'application/octet-stream');
	}//end mimeFor()
}//end class
