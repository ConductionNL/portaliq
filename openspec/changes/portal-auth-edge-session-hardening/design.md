# Design: portal-auth-edge-session-hardening

## Problem

Two independent gaps share one root cause — the auth edge was built to prove
the shape of the pattern (T02/T05 in `supplier-portal`) and deliberately
deferred the "next slice" hardening, which was never picked back up:

1. Signing-secret reuse with NC's global instance secret (blast-radius
   coupling).
2. No server-side session store, so `logout()` and `jti` are decorative today
   — `PortalJwtService::validate()` (`lib/Service/PortalJwtService.php:140-168`)
   only checks signature + issuer + expiry, never the `jti`.

## Secret handling

`PortalSessionService`'s constructor currently builds `PortalJwtService`
inline from `IConfig` rather than via DI (a deliberate choice, per its own
docblock, to keep the service auto-wireable). Preserve that shape, but change
the fallback:

- **Before**: missing dedicated secret → borrow `getSystemValue('secret', ...)`.
- **After**: missing dedicated secret → `PortalSessionService` throws (or
  session issuance/resolution methods return null/fail closed) until a repair
  step (`InitializeSettings`, install-time) has generated and persisted one via
  `ISecureRandom::generate(64, ...)` into `IAppConfig` under
  `jwt_signing_secret`. This makes "no dedicated secret" a transient
  install-time state, never a steady-state fallback.

Rotating the secret is a natural consequence of shipping this: every
`portalSession` signed with the old (system-secret-derived) key stops
validating the moment the app is upgraded. That's acceptable — those tokens
were already riding on a secret this change explicitly deprecates — but it
must be called out (proposal.md already flags it BREAKING).

## Session store (revocation)

`portalSession` (already declared in `portaliq_register.json:86-90`) becomes a
real OpenRegister object per issued token, written the same way
`PortalObjectWriter` writes any other portal-owned object (ADR-022 — no direct
SQL, no new parallel persistence layer):

- `issueSession()` → after minting the JWT, write a `portalSession` row
  (`jti`, `subjectRef`, `audience`, `organisation`, `issuedAt`, `expiresAt`,
  `revokedAt: null`).
- `resolveFromBearer()` → after signature/issuer/expiry checks pass, look up
  the `jti` row; missing row or `revokedAt !== null` → fail closed (same
  return shape as any other rejection: `null`).
- `logout()` → set `revokedAt = now()` on the caller's own `jti` row (found via
  the same bearer resolution the middleware already does).
- Admin "revoke all for organisation X" → bulk-set `revokedAt` on every
  matching, not-yet-revoked row.

This trades a network round-trip to OR on every `/portal/api/*` call for real
revocation. That is the same trade-off procest's own `SupplierSessionService`
already makes (per `DC03` in `supplier-portal/tasks.md`), so it is consistent
with the sibling implementation this app was modeled on.

## Failure mode if OpenRegister is down

`PortalObjectReader`/`PortalObjectWriter` already degrade to `[]`/`null` when
OR is unavailable (existing pattern). Session validation must NOT silently
treat "OR unreachable" as "not revoked" — that would defeat the purpose. On an
OR read failure during `resolveFromBearer()`, fail closed (treat as
unauthenticated), matching the fail-closed posture the rest of the auth edge
already commits to.
