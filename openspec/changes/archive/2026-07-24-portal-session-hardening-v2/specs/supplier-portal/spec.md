---
status: proposed
---

# Spec: supplier-portal (session hardening v2)

## Purpose

Adds session refresh with an absolute-lifetime cap, brute-force / anon rate
limiting on the public portal endpoints, and an append-only portal audit trail
across every mutation, download, and session event — exposed count-only via
metrics. Builds on `portal-auth-edge-session-hardening`. Related: WMEBV ~Awb 2:25
(burden of proof), agent-E org wish D7 (audit trails), ADR-005 (fail-closed),
ADR-022 (writes via OpenRegister), Archiefwet (consume OR retention).

## ADDED Requirements

### Requirement: Session refresh rotates the token within an absolute cap

The server MUST expose `POST /portal/api/session/refresh`. Given a valid,
unexpired, not-yet-revoked bearer, it MUST mint a NEW session with a NEW `jti`,
revoke the OLD `jti` (so the previous bearer stops validating), and slide the
expiry forward — capped by an absolute maximum session lifetime (config, default
8h) measured from the original login. A refresh on a revoked, expired, or
past-the-cap bearer, or when the signing secret is unconfigured, MUST fail closed
with a generic error and mint nothing.

#### Scenario: A valid bearer refreshes and rotates its jti

- **GIVEN** a subject with a valid, unexpired bearer within the absolute lifetime
- **WHEN** they POST to `/portal/api/session/refresh`
- **THEN** a new bearer with a new `jti` and a slid-forward expiry is returned, and the old `jti` is revoked (the old bearer no longer validates)
- @e2e tests/e2e/portal-session-refresh.spec.ts

#### Scenario: Refresh past the absolute cap is refused

- **GIVEN** a bearer whose original login is older than the absolute maximum lifetime
- **WHEN** they attempt to refresh
- **THEN** the refresh fails closed with a generic error — the subject must re-authenticate — and no new token is minted
- @e2e exclude absolute-cap invariant — covered by PHPUnit; no UI surface

#### Scenario: Refresh on a revoked or expired bearer fails closed

- **GIVEN** a bearer that is revoked or already expired
- **WHEN** they attempt to refresh
- **THEN** the refresh fails closed, nothing is minted, and the response is the same generic error as any other rejection
- @e2e exclude fail-closed refresh — covered by PHPUnit; no UI surface

### Requirement: Public portal endpoints are rate limited

The server MUST apply Nextcloud anon rate-limit / brute-force-protection
attributes with conservative defaults to the public session endpoints (especially
`dev-login`) and the collection / create / update / action endpoints, so the
public surface is not brute-forceable. The limits MUST combine with, not replace,
the existing fail-closed middleware and `jti` revocation.

#### Scenario: Repeated dev-login attempts are throttled

- **GIVEN** an anonymous client hammering `POST /portal/api/session/dev-login`
- **WHEN** it exceeds the configured anon rate limit
- **THEN** further attempts within the window are throttled (429), not processed
- @e2e exclude rate-limit attribute — asserted by an integration/attribute test; no distinct UI flow

#### Scenario: The scoped-CRUD surface carries a limit

- **GIVEN** the collection / create / update / action endpoints
- **WHEN** their route methods are inspected
- **THEN** each declares an anon rate-limit posture with a sane default
- @e2e exclude posture check — covered by a static/attribute test; no UI surface

### Requirement: Append-only portal audit trail on every mutation, download, and session event

The server MUST write a `portalAuditEntry` (append-only, `publicRead:false`) for
every portal mutation (`create`, `update`, `forward`), every `download`, and
every session event (`login`, `logout`, `refresh`): `jti`, `subjectRef`,
`organisation`, `appId`, `verb`, target `register`/`schema`/`id`, `timestamp`.
The entry MUST be a fact record and MUST NOT carry payload content. Audit writing
MUST NOT fail the audited action — a `record()` failure is caught, logged, and
never propagated. The audit count MUST be exposed count-only via
`MetricsController`, never the subjects.

#### Scenario: A mutation and a session event are both audited

- **GIVEN** a subject who logs in, creates an object, and logs out
- **WHEN** each action completes
- **THEN** a `portalAuditEntry` exists for `login`, `create` (with the target register/schema/id), and `logout`, each with the session `jti`, subject, organisation, and timestamp — and none carries the object's payload
- @e2e exclude audit-record contract — covered by PHPUnit across the write/session paths; no UI surface

#### Scenario: An audit write failure never reverses the action

- **GIVEN** an action whose audit `record()` throws
- **WHEN** the action completes
- **THEN** the action still returns its normal success, the audit failure is logged, and the action is not reversed
- @e2e exclude failure-isolation invariant — covered by PHPUnit; no UI surface

#### Scenario: The audit count is exposed count-only

- **GIVEN** some audit entries across verbs
- **WHEN** `MetricsController` output is read
- **THEN** it reports audit-entry counts (e.g. by verb) with no subject identity, target id, or payload exposed
- @e2e exclude metrics count-only — covered by PHPUnit; no UI surface

## Non-Functional Requirements

- **Security (ADR-005):** refresh fails closed on every rejection with a generic
  error and rotates (never duplicates) the token; rate limits guard the public
  surface; audit entries carry no payload; metrics are count-only.
- **Reliability:** audit writing is failure-isolated from the audited action.
- **Retention (Archiefwet):** audit + session records consume OpenRegister's
  `_retention`; portaliq does not rebuild a purge (design.md).

## Acceptance Criteria

- `POST /portal/api/session/refresh` rotates the `jti`, revokes the old, slides
  the expiry within an absolute cap (default 8h), and fails closed on
  revoked/expired/past-cap/unconfigured
- The session + scoped-CRUD + action endpoints carry anon rate-limit /
  brute-force attributes with sane defaults
- A `portalAuditEntry` is written for create/update/forward/download/login/
  logout/refresh; it carries no payload; a write failure never reverses the action
- The audit count is exposed count-only via metrics
- `portalAuditEntry` is added to `portaliq_register.json`; behaviour documented in
  README

## Notes

- The `download` verb is emitted via the hook placed by
  `portal-document-download`; this change supplies the `AuditTrailService`.
- Tamper-evident hash-chaining and SIEM export are a later slice.
