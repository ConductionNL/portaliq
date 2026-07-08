<?php

/**
 * Portaliq PortalPageController
 *
 * Serves the per-subject external portal as a standalone SPA with ZERO Nextcloud
 * chrome — the tilburg-woo (softwarecatalogus) frontend, repointed at Portaliq's
 * subject-scoped `/portal/api` and hosted here (ADR-063, Decision-0: build ON
 * tilburg-woo). The built bundle lives under the app's `portal-ui/` directory;
 * this controller streams its static assets and falls back to `index.html` for
 * client-side (react-router) routes. Public — the auth edge is the SPA's own
 * bearer session against `/portal/api/session`, not a Nextcloud session.
 *
 * The `/portal/api/*` routes are registered BEFORE the `/portal/{path}` SPA
 * catch-all (appinfo/routes.php), so the scoped API is never swallowed here.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the bundled tilburg-woo portal SPA (`portal-ui/`) as an in-app public page.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T08
 */
class PortalPageController extends Controller
{
    /**
     * Absolute path to the bundled built portal SPA.
     *
     * @var string
     */
    private string $root;

    /**
     * Constructor.
     *
     * @param IRequest $request The request.
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
        $resolved   = realpath(__DIR__.'/../../portal-ui');
        $this->root = ($resolved !== false) ? $resolved : (__DIR__.'/../../portal-ui');
    }//end __construct()

    /**
     * Serve the SPA entry (`/portal`).
     *
     * @return Response
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T08
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): Response
    {
        return $this->render(path: '');
    }//end index()

    /**
     * Serve a bundled asset, or fall back to the SPA entry for client routes
     * (`/portal/{path}`).
     *
     * @param string $path The requested sub-path.
     *
     * @return Response
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T08
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function catchAll(string $path=''): Response
    {
        return $this->render(path: $path);
    }//end catchAll()

    /**
     * Resolve the request path to a bundled file (path-traversal safe) and return
     * it, or the SPA `index.html` when the path is empty / not a real bundled file
     * (react-router client route).
     *
     * @param string $path The requested sub-path.
     *
     * @return Response
     */
    private function render(string $path): Response
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $resolved = realpath($this->root.'/'.$relative);

        // SPA fallback: empty, traversal outside root, or not a real file.
        if ($relative === ''
            || $resolved === false
            || strncmp($resolved, $this->root, strlen($this->root)) !== 0
            || is_file($resolved) === false
        ) {
            $resolved = $this->root.'/index.html';
        }

        $content = @file_get_contents($resolved);
        if ($content === false) {
            return new DataDisplayResponse('Portal not built', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $isIndex  = (substr($resolved, -10) === 'index.html');
        $response = new DataDisplayResponse($content, Http::STATUS_OK, ['Content-Type' => $this->mimeFor(file: $resolved)]);

        // index.html + runtime-config.js are per-instance and must never cache.
        $noCache = ($isIndex === true || substr($resolved, -17) === 'runtime-config.js');
        if ($noCache === true) {
            // The bundled SPA loads its own same-origin scripts/styles/fonts and
            // calls the same-origin /portal/api. Relax the default (`default-src
            // 'none'`) CSP so it can run — start from an EMPTY policy (no nonce /
            // strict-dynamic that would make the browser ignore 'self') and allow
            // exactly what the same-origin SPA needs.
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
        } else {
            // Hash-named static assets are immutable.
            $response->cacheFor(86400);
        }

        return $response;
    }//end render()

    /**
     * Map a file extension to a Content-Type.
     *
     * @param string $file The file path.
     *
     * @return string
     */
    private function mimeFor(string $file): string
    {
        $map = [
            'html'  => 'text/html; charset=utf-8',
            'js'    => 'application/javascript',
            'css'   => 'text/css',
            'json'  => 'application/json',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'ico'   => 'image/x-icon',
            'webp'  => 'image/webp',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'txt'   => 'text/plain; charset=utf-8',
            'map'   => 'application/json',
        ];

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return ($map[$ext] ?? 'application/octet-stream');
    }//end mimeFor()
}//end class
