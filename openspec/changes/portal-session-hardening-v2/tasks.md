# Tasks: portal-session-hardening-v2

> Session refresh with an absolute cap, anon rate limiting on the public
> endpoints, and an append-only `portalAuditEntry` trail exposed count-only.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [ ] T01 — Add `session_max_lifetime` config (default 8h) and carry an origin-login timestamp (`authTime`/`originIssuedAt`) through `PortalSessionService::issueSession()` / the `portalSession` chain.
- [ ] T02 — Add `PortalSessionService::refreshSession()`: resolve the caller's bearer, verify not-revoked/not-expired/within-absolute-cap/configured, mint a new session (new `jti`), and revoke the old `jti`; fail closed on every rejection with a generic error.
- [ ] T03 — Add `POST /portal/api/session/refresh` route in `appinfo/routes.php` (before the `/portal/{path}` catch-all) + `SessionController::refresh()` (`#[PublicPage]` `#[NoCSRFRequired]`).
- [ ] T04 — SPA: call refresh ahead of expiry from `src/portal/App.jsx` / `src/portal/lib/portalApi.js` and swap the stored bearer.
- [ ] T05 — Apply `AnonRateLimit` (+ `BruteForceProtection` where apt) to `SessionController::devLogin/index/refresh/logout` with conservative defaults.
- [ ] T06 — Apply `AnonRateLimit` to `ContributionController::collection/create/update/action` (and download) with sane defaults.
- [ ] T07 — Add the `portalAuditEntry` schema to `lib/Settings/portaliq_register.json` (`jti`, `subjectRef`, `organisation`, `appId`, `verb`, `register`, `schema`, `id`, `timestamp`; `publicRead:false`/`publicWrite:false`) and bump the register `version`.
- [ ] T08 — Create `lib/Service/AuditTrailService.php` with a failure-isolated `record(...)` writing an append-only `portalAuditEntry` via `PortalObjectWriter` (no payload content).
- [ ] T09 — Call `AuditTrailService::record()` for `login`/`logout`/`refresh` in `PortalSessionService`/`SessionController` and `create`/`update`/`forward` in `ContributionController` (the `download` call site is provided by `portal-document-download`).
- [ ] T10 — Surface count-only `portaliq_audit_entries_total` (by verb) in `lib/Controller/MetricsController.php` — no subject/target/payload.
- [ ] T11 — Unit test refresh: rotate + revoke-old + expiry slide; refuse past-cap; fail closed on revoked/expired/unconfigured.
- [ ] T12 — Unit / attribute test rate limits present on the session + scoped-CRUD + action endpoints.
- [ ] T13 — Unit test `AuditTrailServiceTest`: entry written per verb with no payload; `record()` failure never reverses the action; metrics count-only.
- [ ] T14 — Add Playwright e2e: keep a session alive past the fixed TTL via refresh, and confirm the old bearer is rejected after refresh (closes the `@e2e exclude` markers on the refresh scenarios).
- [ ] T15 — Document refresh, the absolute cap, rate limits, and the audit trail in `README.md`; add the requirements to the canonical `openspec/specs/supplier-portal` spec on sync.
- [ ] T16 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (route-auth, semantic-auth, unsafe-auth-resolver, security-change-has-tests, spec-coverage, e2e-coverage).
