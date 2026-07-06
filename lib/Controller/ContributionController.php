<?php

/**
 * Portaliq Contribution Controller
 *
 * Protected portal API: returns the aggregated portal contributions the
 * authenticated subject may see (the declarative manifest — collections +
 * actions each contributing app registered), reads collection objects, creates
 * whitelisted objects, and forwards declared endpoint actions server-to-server
 * (contract v2, A6). Guarded by PortalAuthMiddleware via the PortalProtected
 * marker, so it fails closed (401) without a valid session; the subject is read
 * from the validated bearer, never from a client param. Every handler
 * authorises against the subject's OWN aggregated manifest — already
 * trust-filtered by the registry — and re-checks the matched entry's minTrust
 * as defense in depth (ADR-005).
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/contract-v2/tasks.md#T3
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T8
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

/**
 * Serves the authenticated subject's aggregated portal contributions.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- PortalSessionService::trustSatisfies
 * is deliberately THE single trust comparator (contract-v2 design decision);
 * every re-check calls it statically so the ordering can never fork.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the complexity is
 * fail-closed authorisation guards on an auth edge (ADR-005), one per attack
 * surface; collapsing them would trade auditability for a score.
 */
class ContributionController extends Controller implements PortalProtected
{
    /**
     * HTTP methods an endpoint action may declare (contract v2, A6).
     */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Timeout (seconds) for the server-to-server action forward.
     */
    private const FORWARD_TIMEOUT = 10;

    /**
     * Constructor.
     *
     * @param IRequest                   $request       The request object.
     * @param PortalContributionRegistry $registry      The contribution aggregator.
     * @param PortalSessionService       $session       Resolves the subject from the bearer.
     * @param PortalObjectReader         $reader        Subject-scoped OR reader.
     * @param PortalObjectWriter         $writer        Subject-scoped OR writer.
     * @param IClientService             $clientService HTTP client for the A6 action forward.
     * @param IURLGenerator              $urlGenerator  Resolves instance-local endpoint paths.
     */
    public function __construct(
        IRequest $request,
        private readonly PortalContributionRegistry $registry,
        private readonly PortalSessionService $session,
        private readonly PortalObjectReader $reader,
        private readonly PortalObjectWriter $writer,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the subject from the bearer (fail-closed). PortalAuthMiddleware
     * has already gated protected access; this re-derives the subject reliably
     * for the handler without depending on request-scoped DI sharing.
     *
     * @return array<string, mixed>|null
     */
    private function subject(): ?array
    {
        return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
    }//end subject()

    /**
     * List the contributions the authenticated subject may see.
     *
     * The route is marked #[PublicPage] because portal subjects are not
     * Nextcloud users; PortalAuthMiddleware enforces the bearer session and
     * fails closed before this method runs.
     *
     * @return JSONResponse The aggregated contribution manifest.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $subject = $this->subject();
        if ($subject === null) {
            // Defensive: the middleware should already have failed closed.
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse($this->registry->aggregateFor($subject));
    }//end index()

    /**
     * Read the objects in one contribution collection, scoped to the subject.
     *
     * Authorises first: the (register, schema) must appear in one of the
     * subject's own contributions, so a subject can only read collections its
     * apps granted it. The subject reference is derived from the bearer,
     * never the client. A declared `fields` whitelist (any collection kind,
     * including inbox) is forwarded to the reader untouched — `null` means
     * "no projection, full rows" (field-projection change).
     *
     * @param string $register The register of the collection.
     * @param string $schema   The schema of the collection.
     *
     * @return JSONResponse The subject's rows, or 401 / 403.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T05
     * @spec openspec/changes/contract-v2/tasks.md#T3
     * @spec openspec/changes/contract-v2/tasks.md#T5
     * @spec openspec/changes/field-projection/tasks.md#T2
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function collection(string $register, string $schema): JSONResponse
    {
        $subject = $this->subject();
        if ($subject === null) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        $match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema);
        if ($match === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $collection = $match['collection'];

        // Defense in depth (contract v2, A3): the aggregate is already
        // trust-filtered, but the matched entry's minTrust is re-checked here
        // so a direct request can never slip below the threshold — 403 before
        // any OpenRegister call.
        if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $objects = $this->reader->readCollection(
            register: $register,
            schema: $schema,
            scopeField: (string) ($collection['scopeField'] ?? 'subjectRef'),
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            limit: 200,
            scopeClaim: (string) ($collection['scopeClaim'] ?? ''),
            contributingApp: $match['app'],
            via: ($collection['via'] ?? null),
            audience: (string) ($subject['audience'] ?? ''),
            fields: ($collection['fields'] ?? null)
        );

        return new JSONResponse(['register' => $register, 'schema' => $schema, 'objects' => $objects]);
    }//end collection()

    /**
     * Find the collection matching (register, schema) in the subject's
     * aggregated contributions, or null when the subject is not entitled to it.
     *
     * @param array<string, mixed> $subject  The resolved subject.
     * @param string               $register The requested register.
     * @param string               $schema   The requested schema.
     *
     * @return array{collection: array<string, mixed>, app: string}|null
     */
    private function authorisedCollection(array $subject, string $register, string $schema): ?array
    {
        $aggregate = $this->registry->aggregateFor($subject);
        foreach (($aggregate['contributions'] ?? []) as $contribution) {
            foreach (($contribution['collections'] ?? []) as $collection) {
                if (($collection['register'] ?? '') === $register && ($collection['schema'] ?? '') === $schema) {
                    return [
                        'collection' => $collection,
                        'app'        => (string) ($contribution['app'] ?? ''),
                    ];
                }
            }
        }

        return null;
    }//end authorisedCollection()

    /**
     * Create an object in a collection, owned by the subject.
     *
     * Authorises against the subject's own contributions: there must be a
     * declared `type: create` action for this (register, schema). Only the
     * fields that action whitelists are accepted; the subject ref and tenant are
     * stamped server-side. A subject can therefore only create records it is
     * entitled to, owned by itself.
     *
     * @param string $register The register of the collection.
     * @param string $schema   The schema of the collection.
     *
     * @return JSONResponse The created object, or 401 / 403 / 502.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T06
     * @spec openspec/changes/contract-v2/tasks.md#T3
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function create(string $register, string $schema): JSONResponse
    {
        $subject = $this->subject();
        if ($subject === null) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        $action = $this->authorisedCreateAction(subject: $subject, register: $register, schema: $schema);
        if ($action === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        // Defense in depth (contract v2, A3): re-check the matched action's
        // minTrust — 403 before any OpenRegister write.
        if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $data = $this->whitelist(fields: (array) ($action['fields'] ?? []));

        $created = $this->writer->createObject(
            register: $register,
            schema: $schema,
            scopeField: (string) ($action['scopeField'] ?? 'subjectRef'),
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            data: $data
        );

        if ($created === null) {
            return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['object' => $created]);
    }//end create()

    /**
     * Find a `type: create` action for (register, schema) in the subject's
     * contributions, or null when the subject is not entitled to create there.
     *
     * @param array<string, mixed> $subject  The resolved subject.
     * @param string               $register The requested register.
     * @param string               $schema   The requested schema.
     *
     * @return array<string, mixed>|null
     */
    private function authorisedCreateAction(array $subject, string $register, string $schema): ?array
    {
        $aggregate = $this->registry->aggregateFor($subject);
        foreach (($aggregate['contributions'] ?? []) as $contribution) {
            foreach (($contribution['actions'] ?? []) as $action) {
                if (($action['type'] ?? '') === 'create'
                    && ($action['register'] ?? '') === $register
                    && ($action['schema'] ?? '') === $schema
                ) {
                    return $action;
                }
            }
        }

        return null;
    }//end authorisedCreateAction()

    /**
     * Read the request body and keep only the whitelisted fields.
     *
     * `claims` is server-managed (contract v2, A4) and is dropped here even if
     * a contribution mistakenly whitelists it — a client-supplied claim map
     * must never reach an OpenRegister write.
     *
     * @param array<int, string> $fields The permitted field names.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/contract-v2/tasks.md#T5
     */
    private function whitelist(array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            if ($field === 'claims') {
                continue;
            }

            $value = $this->request->getParam($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }//end whitelist()

    /**
     * Forward a declared endpoint action server-to-server (contract v2, A6).
     *
     * Authorises against the subject's OWN aggregated (already trust-filtered)
     * manifest: the contribution for {appId} must declare an action with
     * {actionId}, a non-empty instance-local endpoint path, and an allowed
     * method, and its minTrust is re-checked — otherwise 403 with NO outbound
     * request. The forward attaches a short-lived signed `X-Portal-Subject`
     * assertion; the client's own Authorization header is NEVER forwarded, and
     * full http(s) URLs are rejected (SSRF guard). The domain app's status and
     * JSON body are relayed as-is; transport failure yields 502.
     *
     * @param string $appId    The contributing app the action belongs to.
     * @param string $actionId The declared action id.
     *
     * @return JSONResponse The relayed response, or 401 / 403 / 502.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T8
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function action(string $appId, string $actionId): JSONResponse
    {
        $subject = $this->subject();
        if ($subject === null) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        $action = $this->authorisedEndpointAction(subject: $subject, appId: $appId, actionId: $actionId);
        if ($action === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $endpoint = (string) $action['endpoint'];
        $method   = strtoupper((string) ($action['method'] ?? 'POST'));

        try {
            $client  = $this->clientService->newClient();
            $options = [
                'headers'     => [
                    'X-Portal-Subject' => $this->session->issueAssertion($subject),
                    'Content-Type'     => 'application/json',
                ],
                'timeout'     => self::FORWARD_TIMEOUT,
                'body'        => $this->requestBody(),
                // Relay non-2xx domain responses instead of throwing, so the
                // domain app's own status codes (e.g. 422) reach the caller.
                'http_errors' => false,
                // The endpoint is instance-local by contract, so the request
                // legitimately targets our own (possibly private) address.
                'nextcloud'   => ['allow_local_address' => true],
            ];
            $url      = $this->urlGenerator->getAbsoluteURL($endpoint);
            $response = match ($method) {
                'GET' => $client->get($url, $options),
                'PUT' => $client->put($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->post($url, $options),
            };
        } catch (Throwable) {
            // Transport failure — mirrors the writer's 502 posture. Never leak
            // transport internals to the portal client.
            return new JSONResponse(['error' => 'forward_failed'], Http::STATUS_BAD_GATEWAY);
        }//end try

        $body    = $response->getBody();
        $decoded = null;
        if (is_string($body) === true && $body !== '') {
            $decoded = json_decode($body, true);
        }

        if (is_array($decoded) === false) {
            $decoded = [];
        }

        return new JSONResponse($decoded, $response->getStatusCode());
    }//end action()

    /**
     * Find an authorised endpoint action {appId, actionId} in the subject's
     * OWN aggregated manifest, or null (fail-closed 403, no outbound call).
     *
     * Requirements (contract v2, A6): the action id matches, the endpoint is a
     * non-empty INSTANCE-LOCAL absolute path (starts with `/`, no scheme, no
     * protocol-relative `//` — SSRF guard), the method is allowed, and the
     * entry's minTrust is satisfied (defense in depth on top of the
     * trust-filtered aggregate).
     *
     * @param array<string, mixed> $subject  The resolved subject.
     * @param string               $appId    The requested contributing app.
     * @param string               $actionId The requested action id.
     *
     * @return array<string, mixed>|null
     */
    private function authorisedEndpointAction(array $subject, string $appId, string $actionId): ?array
    {
        $aggregate = $this->registry->aggregateFor($subject);
        foreach (($aggregate['contributions'] ?? []) as $contribution) {
            if (($contribution['app'] ?? '') !== $appId) {
                continue;
            }

            foreach (($contribution['actions'] ?? []) as $action) {
                if (($action['id'] ?? '') !== $actionId) {
                    continue;
                }

                if ($this->isForwardableAction(action: $action, subject: $subject) === false) {
                    return null;
                }

                return $action;
            }//end foreach

            return null;
        }//end foreach

        return null;
    }//end authorisedEndpointAction()

    /**
     * Whether a matched action is safe to forward: non-empty INSTANCE-LOCAL
     * endpoint path (SSRF guard: no scheme, no protocol-relative `//`), an
     * allowed method, and satisfied minTrust — all fail-closed.
     *
     * @param array<string, mixed> $action  The matched action declaration.
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return bool
     */
    private function isForwardableAction(array $action, array $subject): bool
    {
        $endpoint = ($action['endpoint'] ?? null);
        if (is_string($endpoint) === false || $endpoint === '') {
            return false;
        }

        // SSRF guard: instance-local absolute paths ONLY. Full http(s)://
        // URLs and protocol-relative // paths are rejected.
        if (str_starts_with($endpoint, '/') === false
            || str_starts_with($endpoint, '//') === true
            || str_contains($endpoint, '://') === true
        ) {
            return false;
        }

        $method = strtoupper((string) ($action['method'] ?? 'POST'));
        if (in_array($method, self::ALLOWED_METHODS, true) === false) {
            return false;
        }

        return PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null));
    }//end isForwardableAction()

    /**
     * The raw request body to relay verbatim to the domain endpoint. Portaliq
     * never interprets it — the domain app validates its own input.
     *
     * @return string
     */
    private function requestBody(): string
    {
        // OCP\IRequest does not declare getContent() in every stub set; the
        // runtime request object provides it. Guarded so unit mocks without it
        // simply relay an empty body.
        if (method_exists($this->request, 'getContent') === false) {
            return '';
        }

        $content = $this->request->getContent();
        if (is_string($content) === false) {
            return '';
        }

        return $content;
    }//end requestBody()
}//end class
