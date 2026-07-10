## 1. Dedicated signing secret

- [x] 1.1 Remove the `getSystemValue('secret', ...)` fallback from
      `PortalSessionService::__construct()`
      (`lib/Service/PortalSessionService.php:78-84`).
- [x] 1.2 Add a repair step (or extend `lib/Repair/InitializeSettings.php`) that
      generates a random `jwt_signing_secret` (>= 32 chars, `ISecureRandom`) on
      first install and stores it via `IAppConfig`, only if unset.
- [x] 1.3 `PortalSessionService` fails closed (session issuance/resolution
      returns null / throws, never signs with a placeholder) when no dedicated
      secret is configured — covers the window between install and the repair
      step running.
- [x] 1.4 Surface the secret's configuration state on `AdminSettings`
      (`lib/Settings/AdminSettings.php`) — a simple "configured" / "missing"
      indicator, no secret value ever rendered.

## 2. `portalSession` persistence

- [x] 2.1 `PortalSessionService::issueSession()` writes a `portalSession`
      object via `PortalObjectWriter` (`jti`, `subjectRef`, `audience`,
      `organisation`, `issuedAt`, `expiresAt`, `revokedAt: null`).
- [x] 2.2 `PortalSessionService::resolveFromBearer()` reads the `portalSession`
      row for the validated `jti` after signature/issuer/expiry checks pass;
      missing row or `revokedAt !== null` → return `null` (fail closed).
- [x] 2.3 OR read failure during this lookup → fail closed (treat as
      unauthenticated), never fail open.

## 3. Real logout + admin revocation

- [x] 3.1 `SessionController::logout()`
      (`lib/Controller/SessionController.php:139-142`) resolves the caller's
      bearer and sets `revokedAt = now()` on its `portalSession` row instead of
      returning a static `{ok: true}`.
- [x] 3.2 Add an admin-only action (Admin Settings or a new
      `#[AuthorizedAdminSetting]` endpoint) to revoke every active
      `portalSession` for a given `organisation`.
- [x] 3.3 `src/portal/App.jsx`'s `logout()` (line 131-139) already calls
      `DELETE /portal/api/session` — no client change needed; confirmed the
      request still succeeds after 3.1 (logout() always returns 200; the
      bearer is resolved defensively and a resolution failure is not itself
      an error).

## 4. Tests + gates

- [x] 4.1 PHPUnit: `PortalSessionServiceTest` — issuing without a dedicated
      secret fails closed; a revoked `jti` fails closed even with a valid
      signature; logout revokes only the caller's own session.
- [x] 4.2 PHPUnit: install-time repair step generates a secret exactly once
      (idempotent on re-run).
- [ ] 4.3 Security review (ADR-005) of the new secret-generation + revocation
      paths before merge; Hydra gates green (spdx-headers, forbidden-patterns,
      unsafe-auth-resolver, spec-coverage). — Hydra gates not run as part of
      this apply pass (process/review step, not implementation); flag for the
      PR review stage.
