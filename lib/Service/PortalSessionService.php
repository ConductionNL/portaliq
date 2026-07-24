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
 * Sessions are recorded server-side as a `portalSession` OpenRegister object
 * for revocation (portal-auth-edge-session-hardening): every issued session is
 * written on mint and re-checked by jti on every resolve, so logout and an
 * admin's "revoke all for organisation X" action take effect immediately,
 * before the token's natural expiry.
 *
 * The HMAC signing secret MUST be a dedicated `jwt_signing_secret` configured
 * for this app (>= 16 chars) — it never falls back to Nextcloud's shared
 * instance secret. Until a dedicated secret is configured (the install-time
 * repair step, {@see \OCA\Portaliq\Repair\InitializeSettings}, generates one
 * automatically), the auth edge fails closed: no session is minted, no bearer
 * is accepted.
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
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.3
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use DateInterval;
use DateTimeImmutable;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Mints and resolves Portaliq bearer sessions (fail-closed).
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the auth edge's session
 * lifecycle (mint/resolve/refresh/revoke), each a fail-closed guard chain
 * (ADR-005); collapsing them would trade auditability for a score.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   -- one dependency per
 * distinct responsibility (signing secret, id generation, OR read/write,
 * audit recording) — see ContributionController's identical rationale.
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
     * The OpenRegister register the `portalSession` schema lives in.
     */
    private const SESSION_REGISTER = 'portaliq';

    /**
     * The OpenRegister schema recording issued sessions for revocation.
     */
    private const SESSION_SCHEMA = 'portalSession';

    /**
     * The app config key for the absolute maximum session lifetime override
     * (seconds), measured from the ORIGIN login — a sliding refresh can never
     * stretch a session past this cap (portal-session-hardening-v2).
     */
    private const MAX_LIFETIME_CONFIG_KEY = 'session_max_lifetime';

    /**
     * Default absolute maximum session lifetime: 8 hours.
     */
    private const DEFAULT_MAX_LIFETIME = 28800;

    /**
     * The token minter/validator, built from the configured signing secret, or
     * null when no dedicated secret is configured yet (fail-closed window
     * between install and the repair step generating one).
     *
     * @var PortalJwtService|null
     */
    private readonly ?PortalJwtService $jwt;

    /**
     * Constructor.
     *
     * The HMAC signing secret MUST come from a dedicated `jwt_signing_secret`
     * app config value (>= 16 chars) — it NEVER falls back to Nextcloud's
     * shared instance secret (portal-auth-edge-session-hardening). When unset
     * or too short, `$jwt` stays null and every issue/resolve call fails
     * closed until the install-time repair step generates one. Building
     * PortalJwtService here (rather than via a DI factory) keeps the whole
     * service auto-wireable — only core services are injected.
     *
     * @param IConfig            $config  The configuration source for the secret + the
     *                                    absolute max session lifetime (portal-session-
     *                                    hardening-v2).
     * @param ISecureRandom      $random  Cryptographically secure id generator (jti).
     * @param LoggerInterface    $logger  The logger.
     * @param PortalObjectWriter $writer  Persists issued sessions for revocation.
     * @param PortalObjectReader $reader  Looks up sessions by jti / organisation.
     * @param AuditTrailService  $auditor Records login/logout/refresh session events
     *                                    (portal-session-hardening-v2).
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly ISecureRandom $random,
        private readonly LoggerInterface $logger,
        private readonly PortalObjectWriter $writer,
        private readonly PortalObjectReader $reader,
        private readonly AuditTrailService $auditor,
    ) {
        $secret    = (string) $config->getAppValue(Application::APP_ID, 'jwt_signing_secret', '');
        $this->jwt = self::buildJwtService(secret: $secret);
    }//end __construct()

    /**
     * Build the token minter/validator from a candidate secret, or null when
     * it is empty or too short — the single point that decides whether the
     * auth edge is configured (used once, from the constructor, to keep
     * `$jwt` a single-assignment readonly property).
     *
     * @param string $secret The candidate `jwt_signing_secret` value.
     *
     * @return PortalJwtService|null
     */
    private static function buildJwtService(string $secret): ?PortalJwtService
    {
        if ($secret === '' || strlen($secret) < 16) {
            return null;
        }

        return new PortalJwtService(signingSecret: $secret);
    }//end buildJwtService()

    /**
     * Whether a dedicated signing secret is configured. Surfaced by
     * `AdminSettings` so an operator can see the auth edge is not yet safe to
     * use, rather than discovering it via failed logins.
     *
     * @return bool
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.4
     */
    public function isConfigured(): bool
    {
        return $this->jwt !== null;
    }//end isConfigured()

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
     * Mint a session for an authenticated subject. Records the `login` event.
     *
     * Fails closed when no dedicated signing secret is configured yet — never
     * signs with a placeholder. Records a `portalSession` row on success so the
     * session can be revoked (logout, admin) before its natural expiry.
     *
     * @param string             $subjectRef   Server-derived subject reference.
     * @param string             $audience     External audience ("supplier"|"client").
     * @param string             $organisation Tenant the session is scoped to.
     * @param string             $trust        Assurance level (e.g. "EH3").
     * @param array<int, string> $roles        Roles carried in the session.
     *
     * @return array{token: string, jti: string}|null The minted bearer token +
     *                                                 its id, or null when the
     *                                                 edge is not yet configured.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.3
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
     */
    public function issueSession(
        string $subjectRef,
        string $audience,
        string $organisation,
        string $trust='',
        array $roles=[]
    ): ?array {
        $issued = $this->mintSession(
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation,
            trust: $trust,
            roles: $roles,
            authTime: null
        );
        if ($issued === null) {
            return null;
        }

        $this->auditor->record(
            verb: 'login',
            subjectRef: $subjectRef,
            organisation: $organisation,
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            id: $issued['jti'],
            jti: $issued['jti']
        );

        return $issued;
    }//end issueSession()

    /**
     * Sign a new bearer + persist its `portalSession` row, WITHOUT recording
     * any audit event — the single low-level mint primitive shared by
     * `issueSession()` (records `login`) and `refreshSession()` (records
     * `refresh` instead, so a rotation never ALSO logs a spurious login).
     *
     * Fails closed when no dedicated signing secret is configured yet — never
     * signs with a placeholder.
     *
     * @param string             $subjectRef   Server-derived subject reference.
     * @param string             $audience     External audience ("supplier"|"client").
     * @param string             $organisation Tenant the session is scoped to.
     * @param string             $trust        Assurance level (e.g. "EH3").
     * @param array<int, string> $roles        Roles carried in the session.
     * @param int|null           $authTime     The ORIGIN login's unix timestamp to carry
     *                                         forward unchanged (a refresh rotation); null
     *                                         mints a fresh origin (a genuine new login).
     *
     * @return array{token: string, jti: string}|null The minted bearer token +
     *                                                 its id, or null when the
     *                                                 edge is not yet configured.
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T02
     */
    private function mintSession(
        string $subjectRef,
        string $audience,
        string $organisation,
        string $trust,
        array $roles,
        ?int $authTime
    ): ?array {
        if ($this->jwt === null) {
            $this->logger->warning('Portaliq: session issuance refused — no dedicated jwt_signing_secret configured');
            return null;
        }

        $jti            = $this->random->generate(32, (ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS));
        $now            = new DateTimeImmutable();
        $originAuthTime = ($authTime ?? $now->getTimestamp());

        $token = $this->jwt->createSession(
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation,
            jti: $jti,
            trust: $trust,
            roles: $roles,
            authTime: $originAuthTime
        );

        $this->writer->createObject(
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            scopeField: '',
            subjectRef: $subjectRef,
            organisation: $organisation,
            data: [
                'subjectRef'   => $subjectRef,
                'audience'     => $audience,
                'organisation' => $organisation,
                'jti'          => $jti,
                'trustLevel'   => $trust,
                'issuedAt'     => $now->format(DATE_ATOM),
                'expiresAt'    => $now->add(new DateInterval('PT'.PortalJwtService::DEFAULT_TTL.'S'))->format(DATE_ATOM),
                'revoked'      => false,
                'authTime'     => (new DateTimeImmutable('@'.$originAuthTime))->format(DATE_ATOM),
            ]
        );

        return ['token' => $token, 'jti' => $jti];
    }//end mintSession()

    /**
     * The absolute maximum session lifetime in seconds — config `session_max_lifetime`
     * (default 8h). A non-positive / unparseable override falls back to the default
     * rather than disabling the cap.
     *
     * @return int
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
     */
    private function maxLifetimeSeconds(): int
    {
        $configured = (int) $this->config->getAppValue(Application::APP_ID, self::MAX_LIFETIME_CONFIG_KEY, (string) self::DEFAULT_MAX_LIFETIME);
        if ($configured <= 0) {
            return self::DEFAULT_MAX_LIFETIME;
        }

        return $configured;
    }//end maxLifetimeSeconds()

    /**
     * Resolve an Authorization header to a server-derived subject. FAILS CLOSED.
     *
     * Fails closed when no dedicated signing secret is configured, when the
     * bearer is absent/malformed/forged/expired, or when the token's `jti` has
     * no matching (non-revoked) `portalSession` row — a revoked or unknown jti
     * is rejected exactly like a bad signature, including when the lookup
     * itself fails (OpenRegister unavailable never means "not revoked").
     *
     * @param string|null $authorizationHeader The raw Authorization header value.
     *
     * @return array<string, mixed>|null The subject claim set, or null when the
     *                                   bearer is absent, malformed, forged,
     *                                   expired, or revoked.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.3
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.2
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.3
     */
    public function resolveFromBearer(?string $authorizationHeader): ?array
    {
        if ($this->jwt === null) {
            return null;
        }

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

        $jti = (string) ($claims['jti'] ?? '');
        if ($this->isJtiActive(jti: $jti) === false) {
            $this->logger->debug('Portaliq: bearer rejected', ['reason' => 'revoked or unknown jti']);
            return null;
        }

        return [
            'subjectRef'   => (string) ($claims['sub'] ?? ''),
            'audience'     => (string) ($claims['audience'] ?? ''),
            'organisation' => (string) ($claims['organisation'] ?? ''),
            'trust'        => self::normaliseTrust(trust: ($claims['trust'] ?? '')),
            'roles'        => (array) ($claims['roles'] ?? []),
            'jti'          => $jti,
            // The ORIGIN login's timestamp (portal-session-hardening-v2) — 0
            // for a token minted before this claim existed, which refreshSession()
            // treats as "cannot establish an origin" and refuses (fail-closed).
            'authTime'     => (int) ($claims['authTime'] ?? 0),
        ];
    }//end resolveFromBearer()

    /**
     * Rotate a valid, unexpired, not-yet-revoked bearer into a NEW session with
     * a NEW `jti`, revoking the old one — a sliding renewal capped by the
     * ABSOLUTE maximum session lifetime measured from the original login
     * (portal-session-hardening-v2). Fails closed (returns null, mints
     * nothing) on every rejection: unconfigured signing secret, revoked bearer,
     * expired bearer, malformed bearer, or past the absolute cap — all
     * indistinguishable to the caller, exactly like `resolveFromBearer()`.
     *
     * @param string|null $authorizationHeader The raw Authorization header value.
     *
     * @return array{token: string, jti: string}|null The NEW bearer token + its
     *                                                 id, or null on any rejection.
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T02
     * @spec openspec/specs/supplier-portal/spec.md#session-refresh-rotates-the-token-within-an-absolute-cap
     */
    public function refreshSession(?string $authorizationHeader): ?array
    {
        // Resolving the bearer already fails closed on: unconfigured secret,
        // absent/malformed/forged bearer, expired bearer, and revoked/unknown
        // jti — refresh inherits every one of those rejections for free.
        $subject = $this->resolveFromBearer(authorizationHeader: $authorizationHeader);
        if ($subject === null) {
            return null;
        }

        $oldJti   = (string) ($subject['jti'] ?? '');
        $authTime = (int) ($subject['authTime'] ?? 0);
        if ($oldJti === '' || $authTime <= 0) {
            // No origin timestamp to measure the cap from (a pre-upgrade token,
            // or a malformed claim) — fail closed rather than assume "fresh".
            return null;
        }

        if ((time() - $authTime) >= $this->maxLifetimeSeconds()) {
            $this->logger->debug('Portaliq: refresh refused — past the absolute session lifetime', ['jti' => $oldJti]);
            return null;
        }

        $issued = $this->mintSession(
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            audience: (string) ($subject['audience'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            trust: (string) ($subject['trust'] ?? ''),
            roles: (array) ($subject['roles'] ?? []),
            authTime: $authTime
        );
        if ($issued === null) {
            return null;
        }

        // Rotate: the OLD bearer stops validating from here on. A quiet
        // revoke (no separate `logout` audit entry) — the visible event for
        // this rotation is `refresh`, recorded once, below.
        $this->revokeQuietly(jti: $oldJti);

        $this->auditor->record(
            verb: 'refresh',
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            id: $issued['jti'],
            jti: $oldJti
        );

        return $issued;
    }//end refreshSession()

    /**
     * Whether a `jti` has a corresponding, not-revoked `portalSession` row.
     * A missing row, an unreachable OpenRegister, or an explicitly revoked row
     * ALL resolve to `false` — fail closed, never "not revoked by default".
     *
     * @param string $jti The session's token id.
     *
     * @return bool
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.2
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.3
     */
    private function isJtiActive(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        $row = $this->findSessionByJti(jti: $jti);
        if ($row === null) {
            return false;
        }

        return ($row['revoked'] ?? false) !== true;
    }//end isJtiActive()

    /**
     * Find the `portalSession` row for a `jti`, or null when absent/unreachable.
     *
     * @param string $jti The session's token id.
     *
     * @return array<string, mixed>|null
     */
    private function findSessionByJti(string $jti): ?array
    {
        if ($jti === '') {
            return null;
        }

        $rows = $this->reader->readCollection(
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            scopeField: 'jti',
            subjectRef: $jti,
            limit: 2
        );

        return ($rows[0] ?? null);
    }//end findSessionByJti()

    /**
     * Revoke the session identified by `jti` (logout). Records the `logout`
     * event. A no-op (returns false, no error, no audit entry) when the jti is
     * empty or the row cannot be found — logging out an already-invalid
     * session is not itself an error.
     *
     * @param string $jti The session's token id to revoke.
     *
     * @return bool True when a matching session was found and marked revoked.
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
     */
    public function revoke(string $jti): bool
    {
        $row = $this->revokeQuietly(jti: $jti);
        if ($row === null) {
            return false;
        }

        $this->auditor->record(
            verb: 'logout',
            subjectRef: (string) ($row['subjectRef'] ?? ''),
            organisation: (string) ($row['organisation'] ?? ''),
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            id: $jti,
            jti: $jti
        );

        return true;
    }//end revoke()

    /**
     * Mark a session revoked WITHOUT recording any audit event — the shared
     * primitive behind `revoke()` (records `logout`) and `refreshSession()`'s
     * old-jti rotation (recorded once, as `refresh`, not also as a `logout`).
     *
     * Calls `PortalObjectWriter::updateObject()` with an EMPTY `scopeField` /
     * `subjectRef` / `organisation` — this is a privileged, internal
     * revocation of a row Portaliq itself already located by `jti`
     * (`findSessionByJti()`), not a subject-scoped write, so there is no
     * ownership re-check to satisfy (an empty `scopeField` skips it, exactly
     * like `issueSession()`'s own `portalSession` write).
     *
     * @param string $jti The session's token id to revoke.
     *
     * @return array<string, mixed>|null The row that was revoked (for the
     *                                    caller's own audit fields), or null
     *                                    when the jti is empty, unknown, or
     *                                    the OR write failed.
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T02
     */
    private function revokeQuietly(string $jti): ?array
    {
        if ($jti === '') {
            return null;
        }

        $row  = $this->findSessionByJti(jti: $jti);
        $uuid = $this->rowId(row: $row);
        if ($row === null || $uuid === null) {
            return null;
        }

        $updated = $this->writer->updateObject(
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            scopeField: '',
            subjectRef: '',
            organisation: '',
            id: $uuid,
            data: ['revoked' => true]
        );

        if ($updated === null) {
            return null;
        }

        return $row;
    }//end revokeQuietly()

    /**
     * Revoke every active `portalSession` for an organisation (admin incident
     * response — e.g. a supplier reports device theft). Already-revoked rows
     * are left untouched; unreachable OpenRegister yields zero revocations
     * (fail-closed reporting, never a partial silent success).
     *
     * @param string $organisation The tenant to revoke every session for.
     *
     * @return int The number of sessions revoked.
     *
     * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
     */
    public function revokeAllForOrganisation(string $organisation): int
    {
        if ($organisation === '') {
            return 0;
        }

        $rows = $this->reader->readCollection(
            register: self::SESSION_REGISTER,
            schema: self::SESSION_SCHEMA,
            scopeField: 'organisation',
            subjectRef: $organisation,
            organisation: $organisation,
            limit: 500
        );

        $revoked = 0;
        foreach ($rows as $row) {
            if (($row['revoked'] ?? false) === true) {
                continue;
            }

            $uuid = $this->rowId(row: $row);
            if ($uuid === null) {
                continue;
            }

            $updated = $this->writer->updateObject(
                register: self::SESSION_REGISTER,
                schema: self::SESSION_SCHEMA,
                scopeField: '',
                subjectRef: '',
                organisation: '',
                id: $uuid,
                data: ['revoked' => true]
            );

            if ($updated !== null) {
                $revoked++;
                $this->auditor->record(
                    verb: 'logout',
                    subjectRef: (string) ($row['subjectRef'] ?? ''),
                    organisation: $organisation,
                    register: self::SESSION_REGISTER,
                    schema: self::SESSION_SCHEMA,
                    id: (string) ($row['jti'] ?? ''),
                    jti: (string) ($row['jti'] ?? '')
                );
            }
        }//end foreach

        return $revoked;
    }//end revokeAllForOrganisation()

    /**
     * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
     *
     * @param array<string, mixed>|null $row The normalised row.
     *
     * @return string|null
     */
    private function rowId(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $self     = ($row['@self'] ?? null);
        $selfUuid = null;
        $selfId   = null;
        if (is_array($self) === true) {
            $selfUuid = ($self['uuid'] ?? null);
            $selfId   = ($self['id'] ?? null);
        }

        $candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
        foreach ($candidates as $candidate) {
            if ((is_string($candidate) === true || is_int($candidate) === true) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }//end rowId()

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
     * @throws RuntimeException When no dedicated signing secret is configured
     *                            — should be unreachable in practice, since a
     *                            resolved subject implies `resolveFromBearer()`
     *                            already required `$jwt` to be non-null.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T7
     */
    public function issueAssertion(array $subject): string
    {
        if ($this->jwt === null) {
            throw new RuntimeException('Portaliq: cannot issue an assertion — no dedicated jwt_signing_secret configured');
        }

        return $this->jwt->createAssertion(
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            audience: (string) ($subject['audience'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? ''),
            trust: self::normaliseTrust(trust: ($subject['trust'] ?? '')),
            jti: (string) ($subject['jti'] ?? '')
        );
    }//end issueAssertion()
}//end class
