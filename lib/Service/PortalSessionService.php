<?php

/**
 * Portaliq Portal Session Service
 *
 * The auth-edge session logic: mints bearer sessions for authenticated external
 * subjects and resolves an incoming bearer back to a server-derived subject.
 * Resolution FAILS CLOSED — any malformed / forged / expired / missing bearer
 * yields null, never a partial identity, and the subject reference is only ever
 * taken from the validated token, never from a client parameter (IDOR / ADR-005).
 *
 * Sessions are stateless JWTs today (see PortalJwtService). Recording each
 * session as a `portalSession` object in OpenRegister for server-side revocation
 * is the next slice — the schema already exists.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mints and resolves Portaliq bearer sessions (fail-closed).
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalSessionService
{
    /**
     * The bearer scheme prefix, case-sensitive per RFC 6750.
     */
    private const BEARER_PREFIX = 'Bearer ';

    /**
     * The token minter/validator, built from the configured signing secret.
     *
     * @var PortalJwtService
     */
    private readonly PortalJwtService $jwt;

    /**
     * Constructor.
     *
     * The HMAC signing secret comes from app config, never the request; it falls
     * back to the instance secret so the edge works out of the box in dev. Set a
     * dedicated `jwt_signing_secret` (>= 16 chars) per deployment for production.
     * Building PortalJwtService here (rather than via a DI factory) keeps the whole
     * service auto-wireable — only core services are injected.
     *
     * @param IConfig         $config The configuration source for the secret.
     * @param ISecureRandom   $random Cryptographically secure id generator (jti).
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        IConfig $config,
        private readonly ISecureRandom $random,
        private readonly LoggerInterface $logger,
    ) {
        $secret = (string) $config->getAppValue(Application::APP_ID, 'jwt_signing_secret', '');
        if ($secret === '' || strlen($secret) < 16) {
            $secret = (string) $config->getSystemValue('secret', str_pad(Application::APP_ID, 32, '_'));
        }

        $this->jwt = new PortalJwtService(signingSecret: $secret);
    }//end __construct()

    /**
     * Mint a session for an authenticated subject.
     *
     * @param string             $subjectRef   Server-derived subject reference.
     * @param string             $audience     External audience ("supplier"|"client").
     * @param string             $organisation Tenant the session is scoped to.
     * @param string             $trust        Assurance level (e.g. "EH3").
     * @param array<int, string> $roles        Roles carried in the session.
     *
     * @return array{token: string, jti: string} The minted bearer token + its id.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    public function issueSession(
        string $subjectRef,
        string $audience,
        string $organisation,
        string $trust='',
        array $roles=[]
    ): array {
        $jti   = $this->random->generate(32, (ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS));
        $token = $this->jwt->createSession(
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation,
            jti: $jti,
            trust: $trust,
            roles: $roles
        );

        return ['token' => $token, 'jti' => $jti];
    }//end issueSession()

    /**
     * Resolve an Authorization header to a server-derived subject. FAILS CLOSED.
     *
     * @param string|null $authorizationHeader The raw Authorization header value.
     *
     * @return array<string, mixed>|null The subject claim set, or null when the
     *                                   bearer is absent, malformed, forged, or
     *                                   expired.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    public function resolveFromBearer(?string $authorizationHeader): ?array
    {
        if ($authorizationHeader === null || str_starts_with($authorizationHeader, self::BEARER_PREFIX) === false) {
            return null;
        }

        $token = substr($authorizationHeader, strlen(self::BEARER_PREFIX));
        if ($token === '') {
            return null;
        }

        try {
            $claims = $this->jwt->validate($token);
        } catch (Throwable $e) {
            // Fail closed — never leak why. Debug-level only.
            $this->logger->debug('Portaliq: bearer rejected', ['reason' => $e->getMessage()]);
            return null;
        }

        return [
            'subjectRef'   => (string) ($claims['sub'] ?? ''),
            'audience'     => (string) ($claims['audience'] ?? ''),
            'organisation' => (string) ($claims['organisation'] ?? ''),
            'trust'        => (string) ($claims['trust'] ?? ''),
            'roles'        => (array) ($claims['roles'] ?? []),
            'jti'          => (string) ($claims['jti'] ?? ''),
        ];
    }//end resolveFromBearer()
}//end class
