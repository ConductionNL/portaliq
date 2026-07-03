<?php

/**
 * Portaliq Portal Auth Middleware
 *
 * Guards every controller that implements the PortalProtected marker: before the
 * controller runs it requires a valid bearer session. A missing / invalid bearer
 * throws PortalUnauthorizedException, converted here to a 401 — so a protected
 * method can never execute without an authenticated subject (fail-closed;
 * ADR-005). This is purely the gate; the guarded controller re-derives the
 * subject itself. Public auth-edge endpoints do not implement the marker and are
 * left untouched.
 *
 * @category Middleware
 * @package  OCA\Portaliq\Middleware
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

namespace OCA\Portaliq\Middleware;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Auth\PortalUnauthorizedException;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Throwable;

/**
 * Fail-closed bearer guard for PortalProtected controllers.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalAuthMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param IRequest             $request The request object.
     * @param PortalSessionService $session The session resolver.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly PortalSessionService $session,
    ) {
    }//end __construct()

    /**
     * Require a valid bearer session before a protected controller runs. The
     * guarded controller re-derives the subject itself; this is purely the
     * fail-closed gate.
     *
     * @param object $controller The controller being dispatched.
     * @param string $methodName The method being invoked.
     *
     * @return void
     *
     * @throws PortalUnauthorizedException When no valid bearer session is present.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeController($controller, $methodName): void
    {
        if (($controller instanceof PortalProtected) === false) {
            return;
        }

        $subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
        if ($subject === null) {
            throw new PortalUnauthorizedException(message: 'No valid portal session');
        }
    }//end beforeController()

    /**
     * Convert a portal auth failure to a 401 JSON response.
     *
     * @param object     $controller The controller being dispatched.
     * @param string     $methodName The method being invoked.
     * @param \Throwable $exception  The thrown exception.
     *
     * @return Response
     *
     * @throws Throwable Re-thrown when it is not a portal auth failure.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterException($controller, $methodName, \Throwable $exception): Response
    {
        if ($exception instanceof PortalUnauthorizedException) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        throw $exception;
    }//end afterException()
}//end class
