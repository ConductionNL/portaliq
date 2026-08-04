<?php

/**
 * Hosts the tilburg-woo-ui (Open Tilburg) citizen WOO SPA as a Nextcloud
 * public page. The SPA is built with `PUBLIC_URL=/index.php/apps/portaliq/woo`
 * and bundled under the app's `woo/` directory; this controller serves its
 * static assets and falls back to `index.html` for client-side (react-router)
 * routes. Public — the WOO surface is anonymous open-data (ADR-046: the
 * standalone-SPA pattern, served in-app).
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
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the bundled tilburg-woo-ui SPA (`woo/`) as an in-app public page.
 */
class WooController extends Controller
{

    /**
     * Absolute path to the bundled built SPA.
     *
     * @var string
     */
    private string $root;

    /**
     * Constructor.
     *
     * @param string   $appName The app id.
     * @param IRequest $request The request.
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);
        $resolved = realpath(__DIR__.'/../../woo');
        if ($resolved !== false) {
            $this->root = $resolved;
        } else {
            $this->root = __DIR__.'/../../woo';
        }
    }//end __construct()

    /**
     * Serve the SPA entry (`/woo`).
     *
     * @return Response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function serve(): Response
    {
        return $this->render(path: '');
    }//end serve()

    /**
     * Serve a bundled asset, or fall back to the SPA entry for client routes
     * (`/woo/{path}`).
     *
     * @param string $path The requested sub-path.
     *
     * @return Response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function servePath(string $path=''): Response
    {
        return $this->render(path: $path);
    }//end servePath()

    /**
     * Resolve the request path to a bundled file (path-traversal safe) and
     * return it, or the SPA `index.html` when the path is empty / not a real
     * bundled file (react-router client route).
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

        $content = file_get_contents($resolved);
        if ($content === false) {
            return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $isIndex  = (substr($resolved, -10) === 'index.html');
        $response = new DataDisplayResponse($content, Http::STATUS_OK, ['Content-Type' => $this->mimeFor(file: $resolved)]);

        // Runtime-config.js must never be cached — it is redeployed per instance.
        $noCache = ($isIndex === true || substr($resolved, -17) === 'runtime-config.js');
        if ($noCache === true) {
            // The bundled SPA loads its own same-origin scripts/styles/fonts and
            // boots via an inline script; relax the default (`default-src 'none'`)
            // CSP so it can run. Everything stays same-origin ('self') + data:.
            // Start from an EMPTY policy (no nonce / no strict-dynamic that would
            // make the browser ignore 'self') and allow exactly what the bundled
            // same-origin SPA needs. All script/style/font/img are 'self' + data:.
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
        }//end if

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
        ];

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return ($map[$ext] ?? 'application/octet-stream');
    }//end mimeFor()
}//end class
