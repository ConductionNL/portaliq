---
status: proposed
---

# Spec: supplier-portal (auth-edge session hardening)

## MODIFIED Requirements

### Requirement: Portaliq MUST authenticate suppliers via a separate bearer auth domain

Portaliq SHALL authenticate a supplier through eHerkenning (EH3) and mint a
bearer session carrying a server-derived `subjectRef`, `organisation`, and
trust level; it SHALL NOT rely on a Nextcloud session, and SHALL NOT trust any
client-supplied scope. Every `/portal/api/*` request SHALL be validated by a
middleware that fails closed (401) and injects the server-derived
`subjectRef`.

Sessions SHALL be signed with a secret **dedicated to the portal auth edge**,
generated on install and stored in app config; the edge SHALL NOT fall back to
Nextcloud's shared instance secret. Every issued session SHALL be recorded
server-side (a `portalSession` object) so it can be revoked before its natural
expiry; `resolveFromBearer()` SHALL treat a revoked or unknown session id
(`jti`) as invalid, exactly like a bad signature.

#### Scenario: Supplier logs in with eHerkenning

- **GIVEN** an unauthenticated visitor on the Portaliq shell for an Organisation
- **WHEN** they complete the eHerkenning (EH3) flow brokered by OpenConnector
- **THEN** Portaliq mints a bearer session with `subjectRef` + `organisation` +
  trust level derived from the assertion, signed with the portal's own
  dedicated secret, and records a `portalSession` object for it

#### Scenario: Missing or invalid session fails closed

- **GIVEN** a request to `/portal/api/*` with no bearer, an expired bearer, a
  bearer below the required trust level, or a bearer whose signature is
  invalid
- **WHEN** the middleware evaluates it
- **THEN** the request is rejected with 401 and no data is returned

#### Scenario: The portal never mints or validates sessions without its own secret

- **GIVEN** a fresh Portaliq install where the install-time repair step has
  not yet run (or failed)
- **WHEN** `issueSession()` or `resolveFromBearer()` is called
- **THEN** the operation fails closed — no session is minted, no bearer is
  accepted — rather than silently signing with Nextcloud's shared instance
  secret

#### Scenario: Logout revokes the session server-side

- **GIVEN** an authenticated supplier with an active bearer session
- **WHEN** they call `DELETE /portal/api/session` (logout)
- **THEN** that session's `portalSession` record is marked revoked, and the
  same bearer is rejected by `resolveFromBearer()` on any subsequent request,
  even before its natural expiry

#### Scenario: An admin revokes every session for a compromised organisation

- **GIVEN** an admin suspects a supplier's device or Organisation is
  compromised
- **WHEN** they trigger "revoke all sessions" for that Organisation
- **THEN** every active `portalSession` for that Organisation is marked
  revoked and subsequently rejected by `resolveFromBearer()`

## Notes

- **@e2e** covers logout-then-reuse-bearer returning 401 (tasks.md 4.1 unit
  level; a Playwright e2e is a natural follow-up once T12/T13 land).
