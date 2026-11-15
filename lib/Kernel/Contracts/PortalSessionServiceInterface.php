<?php

/**
 * Contract of PortalSessionService
 *
 * The public surface of PortalSessionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PortalSessionService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSessionServiceInterface {

	/**
	 * Whether a dedicated signing secret is configured. Surfaced by
	 * `AdminSettings` so an operator can see the auth edge is not yet safe to
	 * use, rather than discovering it via failed logins.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.4
	 */
	public function isConfigured(): bool;

	/**
	 * Mint the short, read-only session a redeemed reference link opens
	 * (identity-ways-in-screens D2). It carries `use: reference`, so
	 * `resolveFromBearer()` refuses it like any special-use token and no
	 * route that takes a portal session accepts it; only the reference read
	 * does, through `resolveReferenceFromBearer()`. It is recorded for
	 * revocation and never refreshed.
	 *
	 * @param string $linkId The spent link's id; the subject is its hash.
	 * @param string $caseReference The case number the session may read.
	 * @param string $organisation The tenant.
	 * @param string $register The case collection's register.
	 * @param string $schema The case collection's schema.
	 *
	 * @return array{token: string, jti: string, expiresAt: string}|null Null
	 *         when the auth edge is not configured or a field is empty.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function issueReferenceSession(string $linkId, string $caseReference, string $organisation, string $register, string $schema): ?array;

	/**
	 * Mint a session for an authenticated subject. Records the `login` event.
	 *
	 * Fails closed when no dedicated signing secret is configured yet — never
	 * signs with a placeholder. Records a `portalSession` row on success so the
	 * session can be revoked (logout, admin) before its natural expiry.
	 *
	 * @param string $subjectRef Server-derived subject reference.
	 * @param string $audience External audience ("supplier"|"client").
	 * @param string $organisation Tenant the session is scoped to.
	 * @param string $trust Assurance level (e.g. "EH3").
	 * @param array<int, string> $roles Roles carried in the session.
	 * @param string $branch The vestigingsnummer the login was restricted to, or ''.
	 * @param string $provider The OIDC provider the login came through, or '' (dev-login, Nextcloud mode, integriq route).
	 * @param string $kvk The KVK number of the company an eHerkenning login signs in for, or ''.
	 *
	 * @return array{token: string, jti: string, expiresAt: int, hardExpiresAt: int, idleTimeout: int}|null The minted
	 *         bearer token, its id and the session's times, or null when the edge is not yet configured.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T02
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.3
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-07-24-portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#11
	 */
	public function issueSession(
		string $subjectRef,
		string $audience,
		string $organisation,
		string $trust = '',
		array $roles = [],
		string $branch = '',
		string $provider = '',
		string $kvk = '',
	): ?array;

	/**
	 * Re-issue a whole-company session for one branch, or for the whole
	 * company again with '' (signin-eherkenning-branch T05, REQ-SEB-003).
	 *
	 * Rotates like a refresh, within the same absolute cap. Refused (null)
	 * for a session the login restricted to a branch, for a malformed branch
	 * number and on every rejection refresh has. Whether the branch belongs
	 * to the company is the caller's check (SessionBranchController).
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 * @param string      $branch              A 12-digit branch number, or '' for the whole company.
	 *
	 * @return array{token: string, jti: string, expiresAt: int, hardExpiresAt: int, idleTimeout: int}|null
	 *
	 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	public function rebranchSession(?string $authorizationHeader, string $branch): ?array;

	/**
	 * Rotate a valid, unexpired, not-yet-revoked bearer into a NEW session with
	 * a NEW `jti`, revoking the old one — a sliding renewal capped by the
	 * ABSOLUTE maximum session lifetime measured from the original login
	 * (portal-session-hardening-v2). Fails closed (returns null, mints
	 * nothing) on every rejection: unconfigured signing secret, revoked bearer,
	 * expired bearer, malformed bearer, or past the absolute cap — all
	 * indistinguishable to the caller, exactly like `resolveFromBearer()`.
	 *
	 * With an audience (read from the account, never the request), the new
	 * session carries it instead (invitation-joins-an-unbound-account); the
	 * session's own audience and `supplier` are refused.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 * @param string      $audience            The audience the account holds now, or '' to keep the session's.
	 *
	 * @return array{token: string, jti: string, expiresAt: int, hardExpiresAt: int, idleTimeout: int}|null The NEW
	 *         bearer token, its id and when the rotated session ends, or null on any rejection.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 * @spec openspec/changes/archive/2026-07-24-portal-session-hardening-v2/tasks.md#T02
	 * @spec openspec/specs/supplier-portal/spec.md#session-refresh-rotates-the-token-within-an-absolute-cap
	 */
	public function refreshSession(?string $authorizationHeader, string $audience = ''): ?array;

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
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.3
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.3
	 */
	public function resolveFromBearer(?string $authorizationHeader): ?array;

	/**
	 * Resolve a reference session, and only a reference session. FAILS CLOSED.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function resolveReferenceFromBearer(?string $authorizationHeader): ?array;

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
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.1
	 * @spec openspec/changes/archive/2026-07-24-portal-session-hardening-v2/tasks.md#T09
	 */
	public function revoke(string $jti): bool;

	/**
	 * Revoke every active `portalSession` for an organisation (admin incident
	 * response, e.g. a supplier reports device theft).
	 *
	 * Reads only the rows not yet revoked, page by page until OpenRegister
	 * runs out, BEFORE revoking any (revoking while paging would shift the
	 * pages under the offset), so a live session past the first page is
	 * never missed (security review B1). An unreachable OpenRegister or a
	 * failed write makes the result `complete: false`, so the admin never
	 * reads "0 revoked" for "did not run" (S5). Every revocation is audited
	 * as `admin-revoke` naming the admin, plus one entry for the call (S6).
	 *
	 * @param string $organisation The tenant to revoke every session for.
	 * @param string $admin The Nextcloud user id of the acting admin.
	 * @param string $subjectRef One account only (sign-in-with-an-email-link), or ''.
	 *
	 * @return array{revoked: int, failed: int, complete: bool}
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 * @spec openspec/changes/archive/2026-07-24-portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#12
	 */
	public function revokeAllForOrganisation(string $organisation, string $admin, string $subjectRef = ''): array;

	/**
	 * Revoke one session on an administrator's word, found by its `jti`.
	 * Recorded as `admin-revoke` naming the admin, so the history tells a
	 * staff revocation from the resident's own logout.
	 *
	 * @param string $jti   The session's token id.
	 * @param string $admin The Nextcloud user id of the acting admin.
	 *
	 * @return bool True when a live session was found and marked revoked.
	 *
	 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
	 */
	public function revokeByAdmin(string $jti, string $admin): bool;

	/**
	 * When a resolved session ends: its bearer's expiry, the absolute cap
	 * measured from the origin login, and the idle window. The portal SPAs
	 * schedule their refresh and the warning from these.
	 *
	 * @param array<string, mixed> $subject A subject from resolveFromBearer().
	 *
	 * @return array{expiresAt: int, hardExpiresAt: int, idleTimeout: int, idlePreview: bool}
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 * @spec openspec/changes/archive/2026-10-30-idle-warning-preview/tasks.md#T01
	 */
	public function sessionTimes(array $subject): array;
}//end interface
