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
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Serves the public Portaliq SPA shell.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T08
 */
class PortalPageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest $request The request
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the public portal shell.
     *
     * The white-label runtime config (organisation name, theme, logo, IdP,
     * feature flags) is resolved server-side and injected as
     * `window.RUNTIME_CONFIG` — see T08. The React bundle takes over routing
     * client-side; deep links are handled by catchAll().
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T08
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse
    {
        $response = new TemplateResponse(
            Application::APP_ID,
            'portal',
            [],
            TemplateResponse::RENDER_AS_PUBLIC
        );

        // The portal is a standalone white-label surface; allow it to be
        // embedded by tenant sites. Tighten per-tenant in T08 if needed.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
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
    public function catchAll(string $path=''): TemplateResponse
    {
        return $this->index();
    }//end catchAll()
}//end class
