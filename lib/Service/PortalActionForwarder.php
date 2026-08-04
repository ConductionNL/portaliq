<?php

/**
 * Portaliq Portal Action Forwarder
 *
 * The transport half of the contract v2 A6 endpoint-action forward. The
 * AUTHORISATION half stays with the controller — this class is only ever
 * reached once the subject's own (already trust-filtered) manifest has proven
 * the action forwardable, so it never sees a call it should refuse.
 *
 * SECURITY: the short-lived signed `X-Portal-Subject` assertion is minted here;
 * the client's own Authorization header is NEVER forwarded. The endpoint is
 * instance-local by contract (the caller's SSRF guard rejects schemes and
 * protocol-relative paths before this runs), which is why the request is
 * allowed to target our own possibly-private address.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/contract-v2/tasks.md#T8
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

/**
 * Performs the authorised server-to-server endpoint-action forward.
 *
 * @spec openspec/changes/contract-v2/tasks.md#T8
 */
class PortalActionForwarder
{
    /**
     * Timeout (seconds) for the server-to-server action forward.
     */
    private const FORWARD_TIMEOUT = 10;

    /**
     * Constructor.
     *
     * @param IRequest             $request       The request (source of the raw relayed body).
     * @param IClientService       $clientService HTTP client for the A6 action forward.
     * @param IURLGenerator        $urlGenerator  Resolves instance-local endpoint paths.
     * @param PortalSessionService $session       Mints the signed `X-Portal-Subject` assertion.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly PortalSessionService $session,
    ) {
    }//end __construct()

    /**
     * Forward an already-authorised action to its domain endpoint.
     *
     * When the action declares a `fields` whitelist, the caller passes the
     * rebuilt map and it becomes the ENTIRE forwarded body — an undeclared
     * field (subjectRef, or anything else a client smuggles into the body) can
     * never reach the domain app through the forward. A null `$whitelisted`
     * means the action declares no `fields` and the raw request body is relayed
     * as-is (contract v2, A6): forwards like shillinq's `pay` carry an opaque
     * domain payload with no whitelist.
     *
     * @param array<string, mixed>      $action      The already-authorised action declaration.
     * @param array<string, mixed>      $subject     The resolved subject.
     * @param array<string, mixed>|null $whitelisted The rebuilt whitelisted body, or null to relay raw.
     *
     * @return IResponse|null The domain app's response, or null on transport failure.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T8
     */
    public function forward(array $action, array $subject, ?array $whitelisted=null): ?IResponse
    {
        $body = $this->requestBody();
        if ($whitelisted !== null) {
            $body = (string) json_encode($whitelisted);
        }

        $options = [
            'headers'     => [
                'X-Portal-Subject' => $this->session->issueAssertion($subject),
                'Content-Type'     => 'application/json',
            ],
            'timeout'     => self::FORWARD_TIMEOUT,
            'body'        => $body,
            // Relay non-2xx domain responses instead of throwing, so the
            // domain app's own status codes (e.g. 422) reach the caller.
            'http_errors' => false,
            // The endpoint is instance-local by contract, so the request
            // legitimately targets our own (possibly private) address.
            'nextcloud'   => ['allow_local_address' => true],
        ];

        try {
            $client = $this->clientService->newClient();
            $url    = $this->urlGenerator->getAbsoluteURL((string) $action['endpoint']);

            return match (strtoupper((string) ($action['method'] ?? 'POST'))) {
                'GET' => $client->get($url, $options),
                'PUT' => $client->put($url, $options),
                'PATCH' => $client->patch($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->post($url, $options),
            };
        } catch (Throwable) {
            // Transport failure. The caller mirrors the writer's 502 posture;
            // transport internals never leak to the portal client.
            return null;
        }//end try
    }//end forward()

    /**
     * Decode a relayed domain response body to an array, degrading to `[]` for
     * an empty, non-string or non-array-decoding body.
     *
     * @param IResponse $response The domain app's response.
     *
     * @return array<mixed>
     *
     * @spec openspec/changes/contract-v2/tasks.md#T8
     */
    public function decodeBody(IResponse $response): array
    {
        $body = $response->getBody();
        if (is_string($body) === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end decodeBody()

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
