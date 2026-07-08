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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/contract-v2/tasks.md#T7
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
     * The eIDAS-aligned trust vocabulary, ordered ascending (contract v2, A3).
     * Any subject trust outside this map normalises to `low`; any entry
     * `minTrust` outside it is unsatisfiable (fail-closed both ways).
     */
    private const TRUST_ORDER = [
        'low'         => 1,
        'substantial' => 2,
        'high'        => 3,
    ];

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
     * Normalise a subject trust value to the eIDAS-aligned vocabulary.
     *
     * Any value outside `low|substantial|high` — including empty, legacy `dev`
     * or `EH3` — normalises to `low` (fail-closed; ADR-005). This is the single
     * normalisation point used by the session edge, the registry, and the
     * contribution controller.
     *
     * @param mixed $trust The raw trust value (session claim or config).
     *
     * @return string One of `low`, `substantial`, `high`.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T1
     */
    public static function normaliseTrust(mixed $trust): string
    {
        if (is_string($trust) === true && isset(self::TRUST_ORDER[$trust]) === true) {
            return $trust;
        }

        return 'low';
    }//end normaliseTrust()

    /**
     * Whether a subject trust level satisfies an entry's `minTrust`.
     *
     * Ordering is `low < substantial < high`. A missing `minTrust` (null or
     * empty string) defaults to `low`; an unrecognised `minTrust` value makes
     * the entry unsatisfiable for EVERY subject — a typo must never widen
     * access (fail-closed; ADR-005). The subject side is normalised first, so
     * unknown subject trust is compared as `low`.
     *
     * @param mixed $subjectTrust The subject's trust value (normalised here).
     * @param mixed $minTrust     The entry's declared minimum, or null/'' when absent.
     *
     * @return bool True when the subject meets the threshold.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T1
     */
    public static function trustSatisfies(mixed $subjectTrust, mixed $minTrust): bool
    {
        if ($minTrust === null || $minTrust === '') {
            $minTrust = 'low';
        }

        if (is_string($minTrust) === false || isset(self::TRUST_ORDER[$minTrust]) === false) {
            // Unrecognised minTrust → unsatisfiable for everyone.
            return false;
        }

        return self::TRUST_ORDER[self::normaliseTrust(trust: $subjectTrust)] >= self::TRUST_ORDER[$minTrust];
    }//end trustSatisfies()

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

        // Token-confusion guard (contract v2, A6): a subject assertion — or any
        // future special-use token — is NOT a portal session. A relayed or
        // leaked X-Portal-Subject assertion can never be replayed as a bearer.
        if (($claims['use'] ?? '') !== '') {
            $this->logger->debug('Portaliq: bearer rejected', ['reason' => 'special-use token presented as session']);
            return null;
        }

        return [
            'subjectRef'   => (string) ($claims['sub'] ?? ''),
            'audience'     => (string) ($claims['audience'] ?? ''),
            'organisation' => (string) ($claims['organisation'] ?? ''),
            'trust'        => self::normaliseTrust(trust: ($claims['trust'] ?? '')),
            'roles'        => (array) ($claims['roles'] ?? []),
            'jti'          => (string) ($claims['jti'] ?? ''),
        ];
    }//end resolveFromBearer()

    /**
     * Mint a short-lived `X-Portal-Subject` assertion for a resolved subject.
     *
     * Delegates to PortalJwtService::createAssertion() so the assertion uses
     * exactly the same secret sourcing as sessions. The assertion carries the
     * SESSION's jti (audit correlation to the originating, revocable session)
     * plus `use: "assertion"`, which resolveFromBearer() rejects — an assertion
     * can never come back as a portal session (contract v2, A6).
     *
     * @param array<string, mixed> $subject The resolved subject (from resolveFromBearer).
     *
     * @return string Compact assertion JWT (TTL ~60s).
     *
     * @spec openspec/changes/contract-v2/tasks.md#T7
     */
    public function issueAssertion(array $subject): string
    {
        return $this->jwt->createAssertion(
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            audience: (string) ($subject['audience'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            trust: self::normaliseTrust(trust: ($subject['trust'] ?? '')),
            jti: (string) ($subject['jti'] ?? '')
        );
    }//end issueAssertion()
}//end class
