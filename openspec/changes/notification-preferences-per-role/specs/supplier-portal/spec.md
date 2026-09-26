---
status: proposed
---

# Spec: supplier-portal (a channel opt-out gates dispatch)

## ADDED Requirements

### Requirement: An account's own channel opt-out gates dispatch

`portalAccount` SHALL carry an optional `notificationChannels` object
(today just the `email` key). Before `NotificationDispatchJob` sends or
logs an attempt, it SHALL read `notificationChannels.email` from the
resolved account: a value of exactly `false` SHALL skip the attempt
entirely — no email sent, no `portalNotification` row written, and the
account's `needsAlternativeContact` fallback streak SHALL NOT be affected.
A missing key, or any other value, SHALL be treated as opted in
(fail-open), preserving today's behaviour for every existing account. The
bearer's own account SHALL be able to set this value through
`PATCH /portal/api/identity/details` (an optional `emailNotifications`
field), server-derived to the bearer's own account exactly as
`displayName`/`email` already are on that route.

#### Scenario: An opted-out account sends and logs nothing

- **GIVEN** a `portalAccount` with `notificationChannels: {"email": false}` and a matching trigger
- **WHEN** `NotificationDispatchJob` runs for that account
- **THEN** no email is sent, no `portalNotification` row is created, and the account's failure streak / `needsAlternativeContact` flag is unchanged
- @e2e exclude backend gate — covered by PHPUnit on `NotificationDispatchJobTest`; no UI surface

#### Scenario: An account with no opt-out set is sent to exactly as before

- **GIVEN** a `portalAccount` with no `notificationChannels` key (every account that existed before this change)
- **WHEN** a matching trigger fires
- **THEN** dispatch proceeds exactly as it did before this change (fail-open default)
- @e2e exclude regression guard — covered by PHPUnit on the existing `testSendsAContentFreeEmailAndLogsASentAttempt`, unmodified

#### Scenario: The bearer opts out of their own email channel

- **GIVEN** an authenticated portal session
- **WHEN** the bearer calls `PATCH /portal/api/identity/details` with `emailNotifications: false`
- **THEN** their own `portalAccount.notificationChannels.email` becomes `false`, and no other account's row changes
- @e2e exclude self-service write — covered by PHPUnit on `PortalSelfServiceServiceTest` and `PortalAccountSelfControllerTest`; no UI surface in this change (API-only, see proposal.md Out of Scope)
