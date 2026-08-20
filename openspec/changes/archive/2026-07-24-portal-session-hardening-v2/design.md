# Design: portal-session-hardening-v2

## Refresh: sliding window with an absolute cap

`PortalSessionService::issueSession()` already mints a JWT + writes a
`portalSession` row with `jti`, `issuedAt`, `expiresAt`. Refresh reuses this:

- `POST /portal/api/session/refresh` resolves the caller's bearer via the same
  `resolveFromBearer()` the middleware uses. Only a valid, unexpired,
  not-yet-revoked bearer may refresh.
- On success it mints a NEW session (`issueSession()` → new `jti`, new
  `expiresAt`) and revokes the old `jti` (`revoke()`), so a refreshed session is
  a rotation, not a second live token — a stolen old bearer dies on refresh.
- The sliding renewal is **capped by an absolute maximum lifetime** (config
  `session_max_lifetime`, default 8h) measured from the *original* login, carried
  as an `authTime`/`originIssuedAt` claim (or on the `portalSession` chain).
  A refresh whose new expiry would exceed origin + max is refused — the subject
  must re-authenticate. This bounds how long a single login can be stretched.

Fail-closed cases (all return the same generic failure): revoked bearer, expired
bearer, bearer past the absolute cap, unconfigured signing secret
(`isConfigured()` false), OR unreachable during the revoke/issue.

## Rate limiting

Nextcloud ships `AnonRateLimit` and `BruteForceProtection` attributes; the public
portal endpoints simply do not use them yet. Apply:

- `dev-login` — the tightest limit (it is debug-only but must not be a
  brute-force oracle when a debug instance is exposed).
- `session index` / `refresh` — moderate anon limits.
- collection / create / update / action endpoints — sane per-IP anon limits so
  the scoped-CRUD surface is not hammerable.

Defaults are conservative and documented; they combine with the existing `jti`
revocation and fail-closed middleware rather than replacing them.

## Audit trail

`portalAuditEntry` (new schema, `publicRead:false`/`publicWrite:false`):
`jti`, `subjectRef`, `organisation`, `appId`, `verb`
(create|update|forward|download|login|logout|refresh), target
`register`/`schema`/`id`, `timestamp`. Written append-only via
`PortalObjectWriter` (ADR-022 — no bespoke SQL) by a thin `AuditTrailService`
whose single `record(...)` method is called from:

- `PortalSessionService` — `login`, `logout`, `refresh`.
- `ContributionController` — `create`, `update`, `forward` (the action forward).
- The `portal-document-download` `download` hook (that change places the call;
  this change supplies the service it calls).

The entry is a **fact record**, never carries payload content (that would
duplicate the domain object and widen exposure) — it records *that* a verb
happened against a target, not the data. Exposed **count-only** via
`MetricsController` (e.g. `portaliq_audit_entries_total` by verb), never the
subjects.

### Failure isolation

Audit writing MUST NOT fail the audited action: like the WMEBV receipt, a
`record()` failure is caught and logged, never propagated. An action that
succeeded is never reversed because its audit entry could not be written; the
gap is logged for reconciliation.

## Retention (Archiefwet)

`portalAuditEntry` and the session records are evidentiary. Per the fleet
convention, retention is expressed via OpenRegister's records-management
`_retention` transient (consume OR, do not rebuild a purge in portaliq). This
change writes the entries and notes the retention hook; the policy itself is an
OR-side / operator concern. This mirrors the memory note *"OR SHIPS Archiefwet
stack — consume not rebuild"*.
