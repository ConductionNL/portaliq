# Design: notification-preferences-per-role

## Architecture Overview

No new component joins the system. `PortalSelfServiceService::updateDetails()`
already writes whitelisted fields onto the bearer's own `portalAccount`
(displayName, email-with-confirmation); this adds one more, boolean,
no-confirmation-needed field. `NotificationDispatchJob::doRun()` already has
a sequence of fail-closed guard clauses (missing argument → no account → no
id) before it decides to send; this adds one more guard, deliberately BEFORE
the account/email checks that already exist, so an opt-out is judged before
anything about deliverability is.

## API Design

### `PATCH /portal/api/identity/details` (existing route, one new optional field)

**Request:**
```json
{
  "displayName": "",
  "email": "",
  "emailNotifications": false
}
```
All three fields stay optional and independent, exactly as `displayName`/
`email` already are — a caller may send only `emailNotifications` to change
nothing else.

**Response:** unchanged shape (`{"updated": true, "confirmationPending": bool}`).
An `emailNotifications`-only request needs no confirmation step (unlike an
email change), so `confirmationPending` is `false` when only the channel
preference changed.

## Database Changes

`portalAccount.notificationChannels`: new object property, default absent
(fail-open to opted-in). No migration — OpenRegister schema properties do
not require one, and an absent key reads as opted-in for every pre-existing
account (D-2).

## Nextcloud Integration

- Controllers: `PortalAccountSelfController::updateDetails()` (new optional
  param, existing method)
- Services: `PortalSelfServiceService::updateDetails()` (new optional
  param, existing method)
- BackgroundJob: `NotificationDispatchJob::doRun()` (new guard clause,
  existing method)
- No new events, no new routes, no new mappers.

## Security Considerations

`emailNotifications` is scoped by the same bearer-derived `subjectRef` every
other field on this route already uses — `PortalSelfServiceService::
updateDetails()` resolves the account via `PortalAccountService::
findBySubjectRef()` before writing anything, exactly as it does today for
`displayName`/`email`. No new trust boundary. The dispatch-side read
(`$account['notificationChannels']['email'] ?? true`) is a plain array read
on a record already resolved by `findAccount()`'s own scoped query; nothing
new to guard there either.

## NL Design System

Not applicable — no frontend surface in this change (see proposal.md Out
of Scope).

## Decision log

### D-1: The opt-out returns before logging, not after logging a "failed" attempt

`NotificationDispatchJob` already distinguishes two very different
non-sends: "no matching rule key" (`NotificationDispatchService`, upstream
of this job, returns without enqueuing — nothing logged) and "no email
address" (this job DOES log a `failed` attempt, because that IS a delivery
problem the WMEBV fallback threshold needs to count). An opt-out is neither:
it is not a missing declaration and not a delivery failure — the subject
chose this. Logging it as `failed` would count a deliberate choice toward
`needsAlternativeContact`, which exists to catch a subject who is NOT
reachable, not one who asked not to be reached on this channel. So the gate
follows the "no matching rule key" shape: return early, log nothing,
touch nothing.

### D-2: Fail-open, not fail-closed, on the missing key

Every other gate in this file fails CLOSED (ADR-005): a missing/malformed
value refuses. This one is deliberately the opposite, and the reason is
observable, not stylistic: every `portalAccount` that exists today has NO
`notificationChannels` key, and today's actual behaviour is "always send."
Fail-closed here would silently stop notifying every existing account the
moment this change deploys — a correctness regression dressed as a security
posture. The property is a PREFERENCE, not a permission: ADR-005's
fail-closed principle protects against an attacker forging absence to gain
access; there is no attacker here, only a default that must match what
every account already experiences.

### D-3: One key today, an object shape for tomorrow

`notificationChannels: {"email": true}` rather than a bare
`emailNotifications: true` boolean, even though only one channel exists.
`push-notifications-quiet-hours` (a later change in this same round) adds
the next channel; an object keyed by channel name means that change adds a
key, not a schema migration.

## Trade-offs

- **Self-service via the existing route, not a new one.** `emailNotifications`
  slots into `PATCH /portal/api/identity/details` alongside `displayName`/
  `email` because it is the same shape of thing (a field on your own
  account, no reviewer needed) — adding a second self-service route for one
  boolean would be a distinction without a difference.
- **No portal SPA control in this change.** The API is real and usable
  (e.g. by a future settings page, or directly), but building that page is
  its own reviewable unit of frontend work; bundling it here would make
  this change `mixed`-shaped (schema + backend + frontend) for no forcing
  reason, against the "split first" guidance for mixed-surface changes.

## Open Questions

None.
