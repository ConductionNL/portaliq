<?php

/**
 * Hosts a bundled single-page site as a Nextcloud public page. A bundled site
 * is a directory of build output in the app root; this controller serves its
 * static assets and falls back to `index.html` for client-side routes
 * (ADR-046: the standalone-SPA pattern, served in-app).
 *
 * Which bundle a URL serves is configuration, not code: the route names the
 * bundle in its `defaults` (appinfo/routes.php), and BUNDLES lists the
 * directories this app ships. Today that is one, `woo/`, the Open Tilburg
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
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves a bundled single-page site as an in-app public page.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-bundled-site-is-served-by-a-generic-host
 */
class BundledSiteController extends Controller {

	/**
	 * The bundle directories this app ships, relative to the app root. A
	 * route may only name one of these: `bundle` arrives as a request
	 * parameter, so it is checked against this list and never used as a path.
	 *
	 * @var string[]
	 */
	private const BUNDLES = ['woo'];

	/**
	 * Absolute path to the app root, the directory the bundles live in.
	 *
	 * @var string
	 */
	private string $appRoot;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 */
	public function __construct(string $appName, IRequest $request) {
		parent::__construct(appName: $appName, request: $request);

		// Initialise to the unresolved path, then override when `realpath()`
		// can canonicalise it.
		$this->appRoot = __DIR__ . '/../..';
		$resolved = realpath($this->appRoot);
		if ($resolved !== false) {
			$this->appRoot = $resolved;
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
		$root = $this->bundleRoot(bundle: $bundle);
		if ($root === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		}

		$resolved = $this->bundledFile(root: $root, path: $path);

		$content = false;
		if (is_file($resolved) === true) {
			$content = file_get_contents($resolved);
		}

		if ($content === false) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		}

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
	}//end render()

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

		$root = $this->appRoot . '/' . $bundle;
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
