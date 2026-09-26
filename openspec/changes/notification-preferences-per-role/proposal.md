---
kind: code
---

# Proposal: notification-preferences-per-role

Learniq round 1 competitor sweep, finding 14.4 "Notification settings per
role and channel" (`learniq-round1/compare/findings.md` and
`change-plan.md` in ConductionNL/market-intelligence, 2026-09-25). studytube
lets an admin set per-role which notifications a Teammanager receives;
docebo offers a channel matrix (Email/Platform/mobile push/Slack); talentlms
sends per event category with configurable recipients. Learniq's own
`src/views/LearniqNotificationSettings.vue` is per-user, not per role.
portaliq today has no notification preference of any kind.

## Summary

`portalAccount` gains one boolean opt-out per notification channel
(`notificationChannels`, today just `{"email": true}`), settable through the
account's existing self-service PATCH, and `NotificationDispatchJob`
respects it before sending. An account's own `audience` already IS its
role — one account, one role — so this is "per role" without a separate
role-keyed settings table: every account of a role gets the SAME channel
choice it set for itself, the role being exactly what the account already
is.

## Motivation

`change-plan.md`'s portaliq row classifies this as `kind: config`, sized S,
with no dependency. Reading the actual dispatch mechanism this session
(`openspec/specs/supplier-portal/spec.md`'s "Manifest notification rule keys
drive an out-of-band email", `NotificationDispatchService`,
`NotificationDispatchJob`) found: there is exactly ONE channel today
(email), and NO opt-out of any kind — every account with an email address
is emailed on every matching trigger, unconditionally. A schema-only change
would add a field nothing reads: decorative, not a preference. Making the
preference real needs the dispatch job to consult it, which is PHP
behaviour, not declarative JSON. This proposal is therefore `kind: code`,
sized a step above the corpus's S guess (still small — one schema property,
one gate, one param threaded through an existing self-service route) —
an unattended-session call, recorded here per the workflow rule that a
reasonable assumption belongs in the PR body rather than blocking on
confirmation.

## Affected Projects

- [x] Project: `portaliq` — schema, self-service update, dispatch gate; no
  other project's code changes.

## Scope

### In Scope

- `notificationChannels` object property on `portalAccount`
  (`lib/Settings/portaliq_register.json`), today just the `email` key.
- `PortalSelfServiceService::updateDetails()` gains an optional
  `?bool $emailNotifications = null` parameter; when given, it is written
  to `notificationChannels.email`.
- `PortalAccountSelfController::updateDetails()` gains the matching
  parameter, forwarded through the EXISTING
  `PATCH /portal/api/identity/details` route — no new route.
- `NotificationDispatchJob::doRun()` gates on
  `notificationChannels.email !== false` right after resolving the account,
  before any send is attempted: an opted-out account gets no email AND no
  `portalNotification` log row (mirrors the existing "no matching rule key"
  no-op, not the "no email address" failed-attempt path — see design.md
  D-1 for why the distinction matters).

### Out of Scope

- A portal SPA checkbox for this preference. Self-service is API-only in
  this change (the existing `PATCH /portal/api/identity/details` route,
  extended); a UI control is a natural, small follow-up, deliberately
  deferred to keep this change reviewable.
- Any channel beyond email — push, SMS and in-app all wait on their own
  delivery mechanism existing first (`push-notifications-quiet-hours` is a
  separate, later portaliq change in this round; it is the one that would
  actually add a second channel key here).
- A role-keyed settings TABLE distinct from the account's own preference.
  The account's `audience` already is the role; a future multi-account
  broadcast setting (e.g. "every teacher account defaults to X") is a
  genuinely different, bigger feature this round does not build.

## Approach

Extend the schema, thread one optional parameter through the existing
self-service update path, and add one early-return guard in the dispatch
job. Full detail in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/portaliq_register.json` (`portalAccount.notificationChannels`)
- `lib/Service/Identity/PortalSelfServiceService.php`
- `lib/Controller/PortalAccountSelfController.php`
- `lib/BackgroundJob/NotificationDispatchJob.php`
- Unit tests in the three existing suites covering each of the above.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: An account opts out and never realises they are missing messages
**Severity:** Low — **Mitigation:** fail-open default (missing key = `true`)
means opting out is a deliberate act, not an accidental default; a portal
SPA affordance to see and change the setting is the natural next step,
tracked as an explicit follow-up rather than blocking this change.

### Risk 2: An opted-out account's skip is miscounted as a delivery failure,
falsely flagging `needsAlternativeContact` (the WMEBV notificatieplicht
fallback signal)
**Severity:** Medium — **Mitigation:** the gate returns before any
`portalNotification` row is written at all (the same no-op shape as "no
matching rule key"), so an opt-out never touches the failure-streak count;
a unit test on `NotificationDispatchJobTest` pins that no row is created
and `needsAlternativeContact` is never set for an opted-out account.

## Rollback Strategy

Revert the commit. Additive schema property + one guard clause; no data
migration, no route change.

## Open Questions

None — the `config`→`code` reclassification is the one assumption this
session made unattended; recorded above rather than left implicit.
