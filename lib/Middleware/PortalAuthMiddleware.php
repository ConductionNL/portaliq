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
 * portal-page-provisioning adds an ANONYMOUS-ALLOWED branch: when no bearer
 * resolves, before throwing, the gate checks
 * `PortalContributionRegistry::aggregateAnonymous()` for an entry matching the
 * request — a matching `anonymous: true`, `type: create` action for `create()`'s
 * exact `(register, schema)`, or "any anonymous entry exists at all" for
 * `index()` — and lets the request through when one matches. Every other
 * method, and every request that matches nothing, is gated EXACTLY as before —
 * the bearer-required default is unchanged for any entry that never opts in.
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
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */

declare(strict_types=1);

namespace OCA\Portaliq\Middleware;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Auth\PortalUnauthorizedException;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Throwable;

/**
 * Fail-closed bearer guard for PortalProtected controllers, with an
 * additive anonymous-allowed branch (portal-page-provisioning).
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */
class PortalAuthMiddleware extends Middleware
{
    /**
     * Controller methods the anonymous-allowed branch considers at all.
     * Every other guarded method keeps the unconditional bearer-required
     * default (no anonymous surface is defined for it in this change —
     * `update`/endpoint `action` are explicitly out of scope, design.md).
     */
    private const ANONYMOUS_ELIGIBLE_METHODS = ['index', 'create'];

    /**
     * Constructor.
     *
     * @param IRequest                   $request  The request object.
     * @param PortalSessionService       $session  The session resolver.
     * @param PortalContributionRegistry $registry Resolves the anonymous-reachable
     *                                             surface
     *                                             (portal-page-provisioning).
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly PortalSessionService $session,
        private readonly PortalContributionRegistry $registry,
    ) {
    }//end __construct()

    /**
     * Require a valid bearer session before a protected controller runs,
     * unless the request matches an explicitly anonymous-flagged entry
     * (portal-page-provisioning). The guarded controller re-derives the
     * subject itself (null on the anonymous path); this is purely the
     * fail-closed gate.
     *
     * @param object $controller The controller being dispatched.
     * @param string $methodName The method being invoked.
     *
     * @return void
     *
     * @throws PortalUnauthorizedException When no valid bearer session is
     *                                      present AND no anonymous entry
     *                                      matches the request.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
     */
    public function beforeController($controller, $methodName): void
    {
        if (($controller instanceof PortalProtected) === false) {
            return;
        }

        $subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
        if ($subject !== null) {
            return;
        }

        if ($this->anonymousEntryMatches(methodName: $methodName) === true) {
            return;
        }

        throw new PortalUnauthorizedException(message: 'No valid portal session');
    }//end beforeController()

    /**
     * Whether the current request matches an anonymous-flagged entry for the
     * method being invoked (portal-page-provisioning). Fails closed to false
     * on any method this change does not define an anonymous surface for,
     * and on a `create()` request missing its `register`/`schema` route
     * parameters.
     *
     * @param string $methodName The method being invoked.
     *
     * @return bool
     *
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
     */
    private function anonymousEntryMatches(string $methodName): bool
    {
        if (in_array($methodName, self::ANONYMOUS_ELIGIBLE_METHODS, true) === false) {
            return false;
        }

        $aggregate     = $this->registry->aggregateAnonymous();
        $contributions = (array) ($aggregate['contributions'] ?? []);

        // Index(): any anonymous entry existing at all is enough — the
        // anonymous visitor's SPA needs the page layout (labels, richText,
        // field configs) before it can submit anything.
        if ($methodName === 'index') {
            return count($contributions) > 0;
        }

        return $this->anonymousCreateActionMatches(contributions: $contributions);
    }//end anonymousEntryMatches()

    /**
     * Whether an anonymous `type: create` action matches the request's
     * target `(register, schema)` — bound from the route's {register}/
     * {schema} placeholders, the same shape
     * `ContributionController::authorisedCreateAction()` matches for a
     * bearer-authenticated subject. Fails closed to false when either route
     * parameter is missing.
     *
     * @param array<int, array<string, mixed>> $contributions The anonymous-only contributions.
     *
     * @return bool
     *
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
     */
    private function anonymousCreateActionMatches(array $contributions): bool
    {
        $register = (string) $this->request->getParam('register', '');
        $schema   = (string) $this->request->getParam('schema', '');
        if ($register === '' || $schema === '') {
            return false;
        }

        foreach ($contributions as $contribution) {
            foreach ((array) ($contribution['actions'] ?? []) as $action) {
                if (($action['type'] ?? '') === 'create'
                    && ($action['anonymous'] ?? false) === true
                    && ($action['register'] ?? '') === $register
                    && ($action['schema'] ?? '') === $schema
                ) {
                    return true;
                }
            }
        }

        return false;
    }//end anonymousCreateActionMatches()

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
