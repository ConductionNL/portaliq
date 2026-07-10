<?php

/**
 * Portaliq Session Admin Controller
 *
 * Admin-only incident-response action for the portal auth edge
 * (portal-auth-edge-session-hardening): revoke every active `portalSession`
 * for a given Organisation, e.g. when a supplier reports a stolen device.
 *
 * Deliberately carries NO auth attribute: Nextcloud's SecurityMiddleware
 * default (no #[NoAdminRequired], no #[PublicPage]) is "instance admin + CSRF
 * token required" — exactly the posture this incident-response action needs.
 * #[AuthorizedAdminSetting] (the delegated-admin alternative) is NOT used
 * here because it requires the referenced settings class to implement
 * IDelegatedSettings; `AdminSettings` deliberately stays a plain `ISettings`
 * (full-admin-only, per its own docblock) — see that class if delegated
 * (group-restricted) admin access to this action becomes a requirement.
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
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only session revocation for the portal auth edge.
 *
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
 */
class SessionAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request The request object.
     * @param PortalSessionService $session The session service.
     */
    public function __construct(
        IRequest $request,
        private readonly PortalSessionService $session,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Revoke every active portal session for an Organisation.
     *
     * @param string $organisation The tenant (OpenRegister Organisation) to
     *                             revoke every session for.
     *
     * @return JSONResponse `{revoked: int}`, or 400 when `organisation` is empty.
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
     */
    public function revokeOrganisation(string $organisation=''): JSONResponse
    {
        if ($organisation === '') {
            return new JSONResponse(['error' => 'organisation_required'], Http::STATUS_BAD_REQUEST);
        }

        $revoked = $this->session->revokeAllForOrganisation($organisation);

        return new JSONResponse(['revoked' => $revoked]);
    }//end revokeOrganisation()
}//end class
