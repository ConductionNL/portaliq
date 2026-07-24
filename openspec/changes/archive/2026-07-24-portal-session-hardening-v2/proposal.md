# Proposal: portal-session-hardening-v2 (refresh + rate limiting + action audit)

## Why

`portal-auth-edge-session-hardening` gave the auth edge a dedicated signing
secret and server-side `jti` revocation. Three gaps remain before the edge is
production-grade for external identity flows:

1. **No refresh.** The session TTL is fixed (`PortalJwtService::DEFAULT_TTL`,
   7200s). A subject filling in a long form or reading a case is logged out
   mid-task with no way to extend, and there is no absolute cap either — the
   fixed TTL is both too short for usability and unbounded against re-issue.
2. **No rate limiting.** The public session, collection, and action endpoints
   (`SessionController`, `ContributionController`) carry `#[PublicPage]` and no
   `AnonRateLimit`/`BruteForceProtection` — `dev-login` and the action endpoints
   are brute-forceable. `2fa/tweefactor` and hardened login appear in **38 tender
   requirements**; agent D notes NC's brute-force protection is *"inherited free
   ... combine with jti revocation"* — it just is not wired on yet.
3. **No action audit.** Agent A: audit trail is *"PARTIAL (sessions logged;
   actions/reads not; relies on OR history)."* WMEBV puts the **burden of proof of
   delivery** on the government (~Awb 2:25) and agent E's org wishes list
   **"audit trails incl machtiging chains + WMEBV delivery logging" (D7)** as
   HIGH. There is no append-only, portal-owned record of who did what.

## What Changes

- **`POST /portal/api/session/refresh`** — a valid, unexpired bearer mints a NEW
  JWT with a new `jti`, revokes the old `jti` (reusing the existing
  `PortalSessionService::revoke()` + `portalSession` store), and slides the
  expiry forward — but capped by an **absolute maximum session lifetime**
  (config, default 8h) measured from the original login. A refresh on a revoked
  or expired bearer, or past the absolute cap, fails closed.
- **Rate limiting** — apply `OCP\AppFramework\Http\Attribute\AnonRateLimit`
  (and `BruteForceProtection` where appropriate) to the session endpoints (esp.
  `dev-login`) and the collection / action endpoints with sane defaults, so the
  public surface is not brute-forceable.
- **`portalAuditEntry`** schema + an `AuditTrailService` — append-only records
  (`jti`, `subjectRef`, `organisation`, `appId`, `verb`
  create/update/forward/download/login/logout/refresh, target
  register/schema/id, `timestamp`) written on every portal mutation, download,
  and session event. Exposed **count-only** via `MetricsController`. This is the
  record that satisfies D7 and backs the download audit hook placed by
  `portal-document-download`.

## Out of scope

- Full SIEM export / tamper-evident hash-chaining of the audit log — a later
  slice; this delivers the append-only record and the count metric.
- Retention purge logic — deferred to OpenRegister's records management (see
  design.md); portaliq only writes the entries.

## Dependencies

- Builds on `portal-auth-edge-session-hardening` (`portalSession` store,
  `revoke()`, `isConfigured()`), consumes the audit hook placed by
  `portal-document-download`, and records `create`/`update`/`forward` from the
  existing `ContributionController` write paths.
