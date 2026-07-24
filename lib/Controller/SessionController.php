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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
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
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 60)]
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
     * The tightest anon rate limit of any session endpoint (design.md): a
     * password-less mint must not become a brute-force oracle if a debug
     * instance is ever exposed. `BruteForceProtection`'s delay is registered
     * whenever the response is marked `throttle()`d below (the gate-closed
     * 404 path).
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     * @spec openspec/changes/contract-v2/tasks.md#T1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 60)]
    #[BruteForceProtection(action: 'portaliq_dev_login')]
    public function devLogin(string $subjectRef='dev-supplier', string $audience='supplier', string $organisation='dev-org'): JSONResponse
    {
        if ($this->isDevLoginEnabled() === false) {
            $response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
            // Mark the attempt for Nextcloud's bruteforce throttler — probing
            // for a debug-only endpoint on a production instance is exactly
            // the abuse pattern BruteForceProtection exists to slow down.
            $response->throttle(['reason' => 'dev_login_disabled']);
            return $response;
        }

        // Dev-login is a password-less mint, so it carries the LOWEST assurance
        // level explicitly (contract v2, A3 — eIDAS-aligned trust vocabulary).
        $issued = $this->session->issueSession(
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation,
            trust: 'low',
            roles: [$audience.':read']
        );

        if ($issued === null) {
            // The auth edge fails closed when no dedicated jwt_signing_secret is
            // configured yet (portal-auth-edge-session-hardening) — never signs
            // with a placeholder.
            return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

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
     * End the client session: resolves the caller's own bearer and marks its
     * `portalSession` record revoked, so `resolveFromBearer()` rejects it on
     * any subsequent request, even before its natural expiry. Always responds
     * `{ok: true}` — an already-invalid or unknown bearer is not itself an
     * error (the client's local token is dropped regardless per App.jsx).
     *
     * @return JSONResponse 200.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function logout(): JSONResponse
    {
        $subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
        if ($subject !== null) {
            $this->session->revoke((string) ($subject['jti'] ?? ''));
        }

        return new JSONResponse(['ok' => true]);
    }//end logout()

    /**
     * Rotate the caller's bearer within the absolute session lifetime cap
     * (portal-session-hardening-v2). A valid, unexpired, not-yet-revoked
     * bearer gets a NEW bearer with a NEW `jti`; the OLD bearer is revoked
     * (rotation, not a second live token). Fails closed with the SAME generic
     * 401 on every rejection — revoked, expired, malformed, past the absolute
     * cap, or the edge not yet configured — never distinguishing why.
     *
     * @return JSONResponse 200 with the new bearer, or 401 on any rejection.
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
     * @spec openspec/specs/supplier-portal/spec.md#session-refresh-rotates-the-token-within-an-absolute-cap
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function refresh(): JSONResponse
    {
        $issued = $this->session->refreshSession($this->request->getHeader('Authorization'));
        if ($issued === null) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            [
                'token'     => $issued['token'],
                'tokenType' => 'Bearer',
            ]
        );
    }//end refresh()

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
