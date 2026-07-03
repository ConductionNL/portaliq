<?php

/**
 * Portaliq Session Controller
 *
 * The public auth-edge HTTP surface for the portal SPA. `index()` resolves the
 * caller's bearer to a server-derived subject (fail-closed). `devLogin()` mints
 * a session WITHOUT a real IdP — it is gated behind Nextcloud debug mode or an
 * explicit app flag so it can never issue tokens in production; it exists so the
 * portal is exercisable before the eHerkenning / DigiD broker (dormant, awaiting
 * OpenConnector) is wired. `logout()` ends the client session.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Public auth-edge endpoints for the portal SPA.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class SessionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request The request object.
     * @param PortalSessionService $session The session service.
     * @param IConfig              $config  For the dev-login gate.
     */
    public function __construct(
        IRequest $request,
        private readonly PortalSessionService $session,
        private readonly IConfig $config,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the caller's bearer to a subject. Fails closed with 401.
     *
     * @return JSONResponse 200 with the subject, or 401 when unauthenticated.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
        if ($subject === null) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            [
                'authenticated' => true,
                'subjectRef'    => $subject['subjectRef'],
                'audience'      => $subject['audience'],
                'organisation'  => $subject['organisation'],
                'trust'         => $subject['trust'],
            ]
        );
    }//end index()

    /**
     * Mint a dev session (no real IdP). Gated — 404 unless dev-login is enabled.
     *
     * @param string $subjectRef   The subject reference to embed.
     * @param string $audience     "supplier" or "client".
     * @param string $organisation The tenant to scope to.
     *
     * @return JSONResponse 200 with a bearer token, or 404 when the gate is closed.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function devLogin(string $subjectRef='dev-supplier', string $audience='supplier', string $organisation='dev-org'): JSONResponse
    {
        if ($this->isDevLoginEnabled() === false) {
            return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }

        $issued = $this->session->issueSession(
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation,
            trust: 'dev',
            roles: [$audience.':read']
        );

        return new JSONResponse(
            [
                'token'        => $issued['token'],
                'tokenType'    => 'Bearer',
                'subjectRef'   => $subjectRef,
                'audience'     => $audience,
                'organisation' => $organisation,
            ]
        );
    }//end devLogin()

    /**
     * End the client session. Stateless today; server-side revocation of the
     * `portalSession` record is the next slice.
     *
     * @return JSONResponse 200.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function logout(): JSONResponse
    {
        return new JSONResponse(['ok' => true]);
    }//end logout()

    /**
     * Whether the dev-login gate is open: NC debug mode, or an explicit app flag.
     *
     * @return bool
     */
    private function isDevLoginEnabled(): bool
    {
        if ($this->config->getSystemValueBool('debug', false) === true) {
            return true;
        }

        return $this->config->getAppValue(Application::APP_ID, 'dev_login_enabled', 'no') === 'yes';
    }//end isDevLoginEnabled()
}//end class
