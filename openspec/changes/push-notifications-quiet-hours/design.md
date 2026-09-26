# Design: push-notifications-quiet-hours

## Architecture Overview

```
guardian/staff --> QuietHoursGuardianController / QuietHoursStaffController --> QuietHoursPolicy (the ONE rule)
guardian --> PushSubscriptionController --> ObjectService (pushSubscription)
any future feature --> PushDeliveryService::deliver(subjectRef, title, body, emergency?)
                          --> QuietHoursPolicy::isQuietNow()  (skipped when emergency)
                          --> PushSenderInterface::send()      (immediate)
                          --> or queues a pendingPush row       (deferred)
staff --> EmergencyPushController --> GuardianAudienceFixtureReader::guardiansMatching(target)
                                    --> PushDeliveryService::deliver(..., emergency: true) per guardian
PendingPushDeliveryJob (TimedJob, every 5 min) --> delivers due pendingPush rows via PushSenderInterface
```

## Messaging leaf interface (naming convention reused from `guardian-direct-messages`)

`PushSenderInterface::send(subjectRef, title, body): bool` names the ONE
transport seam. `LoggingPushSender` is the first implementation — it logs
the intended push and returns true, an honest interim (Web Push's VAPID-
signed delivery needs key provisioning, an admin-settings concern out of
scope here — see proposal.md Scope). A real transport implements the same
interface and is aliased in DI (`lib/AppInfo/Application.php`) with no
caller change, mirroring how `InAppMessagingLeaf` was introduced in the
sibling `guardian-direct-messages` change.

## QuietHoursPolicy: the single source of truth

Every method that needs the quiet-hours decision (`isQuietNow`,
`windowEnd`) AND every method that reads/writes the configured window
(`resolveWindow`, `setWindow`) live in ONE class,
`lib/Service/Notifications/QuietHoursPolicy.php` — per the brief ("the
quiet-hours primitive may later move to OpenRegister, so keep the rule in
one service"). `PushDeliveryService` is its only caller for the send
decision.

- `resolveWindow(subjectRef)`: the subject's configured `{start, end}`
  (`HH:MM` 24h), or the documented default (22:00–07:00) when unconfigured.
- `isQuietNow(subjectRef, now?)`: handles a window that wraps midnight.
- `windowEnd(subjectRef, now?)`: the next moment the window ends, used as a
  deferred push's `deliverAfter`.

## Emergency bypass — the one deliberate fail-OPEN in this app

Every other path in this app fails CLOSED (ADR-005). The emergency
(noodmelding) push is the one deliberate exception: failing closed on a
safety broadcast (e.g. a lockdown alert) is the wrong default. This is
named explicitly, not left as an accidental gap — `PushDeliveryService`
checks `$emergency` FIRST and skips `QuietHoursPolicy` entirely on that
branch; the normal-path fail-closed behaviour (unconfigured window →
documented default, not "always allow") is unchanged for non-emergency
sends.

## API Design

### `POST /api/push/subscribe` (guardian)
Body: `{endpoint, keys: {p256dh, auth}, deviceRef?}`.

### `POST /api/push/unsubscribe` (guardian)
Body: `{endpoint}`.

### `GET/POST /api/quiet-hours` (guardian)
Get/set the guardian's own window.

### `GET/POST /api/staff/quiet-hours` (staff)
Get/set the staff member's own window.

### `POST /api/staff/emergency-push` (staff)
Body: `{target: {schoolRef?, groupRefs?, childRefs?}, title, body}`.
Response: `{recipientCount}`.

## Nextcloud Integration

- Controllers: `PushSubscriptionController` (guardian, `PortalProtected`),
  `QuietHoursGuardianController` (guardian, `PortalProtected`),
  `QuietHoursStaffController` (staff, `#[NoAdminRequired]`),
  `EmergencyPushController` (staff, `#[NoAdminRequired]`).
- Services: `QuietHoursPolicy`, `PushSenderInterface`, `LoggingPushSender`,
  `PushDeliveryService`, `GuardianAudienceFixtureReader` (own copy, full
  shape including `guardiansMatching()`, needed for the emergency broadcast
  target resolution), `NewsAudienceMatcher` (reused, unmodified).
- Background job: `PendingPushDeliveryJob extends TimedJob`, 5-minute
  interval, `TIME_INSENSITIVE`, `setAllowParallelRuns(false)` — mirrors
  `TrafficReportJob`'s existing shape in this app.
- DI: `Application::register()` aliases `PushSenderInterface` to
  `LoggingPushSender`.

## Security Considerations

- The emergency bypass is logged with the sending staff member's own
  subjectRef and the resolved recipient count (mirrors
  `AuditTrailService`'s existing convention) — attributable, not anonymous.
- A guardian may only subscribe/set quiet hours for THEIR OWN subjectRef,
  derived from the validated bearer, never a client parameter.
- `EmergencyPushController` requires a Nextcloud session
  (`#[NoAdminRequired]`) — any authenticated staff member may broadcast; a
  narrower "must teach this group" check is intentionally not applied here
  (an emergency broadcast is a school-wide safety concern, not a per-group
  teaching permission) — documented as a scope decision, not an oversight.

## NL Design System

No UI ships in this change (API-only).

## File Structure

```
lib/
  Service/
    Notifications/
      QuietHoursPolicy.php
      PushSenderInterface.php
      LoggingPushSender.php
      PushDeliveryService.php
      PendingPushService.php
    GuardianAudienceFixtureReader.php   (own copy, full shape)
    NewsAudienceMatcher.php             (reused, unmodified)
  Controller/
    PushSubscriptionController.php
    QuietHoursGuardianController.php
    QuietHoursStaffController.php
    EmergencyPushController.php
  BackgroundJob/
    PendingPushDeliveryJob.php
lib/Settings/portaliq_register.json   (schemas: pushSubscription, notificationQuietHours, pendingPush, guardianAudienceFixture)
appinfo/routes.php
appinfo/info.xml   (background-jobs entry)
tests/Unit/Service/Notifications/QuietHoursPolicyTest.php
tests/Unit/Service/Notifications/PushDeliveryServiceTest.php
tests/Unit/BackgroundJob/PendingPushDeliveryJobTest.php
tests/Unit/Controller/PushSubscriptionControllerTest.php
tests/Unit/Controller/QuietHoursGuardianControllerTest.php
tests/Unit/Controller/EmergencyPushControllerTest.php
```

## Seed Data

### Schema: `guardianAudienceFixture`

Same 4 rows as the sibling changes — see `news-and-newsletter-authoring`'s
design.md for the exact values.

### Schema: `pushSubscription` / `notificationQuietHours` / `pendingPush`

No seed rows — all three are write-only through the API (a subscription,
a configured window, and a queued push are all per-subject transactional
records), matching every other transactional schema in this app.

## Declarative-vs-imperative decision (ADR-031)

- **Deferred delivery** (`pendingPush` queue + `PendingPushDeliveryJob`):
  imperative — ADR-031's exception applies (scheduled bulk work,
  `x-openregister-notifications` is not shaped for a time-windowed
  defer-and-retry queue).
- **The quiet-hours decision itself**: imperative by design, per the brief
  — kept in `QuietHoursPolicy` specifically so it is ONE file to redirect
  to an OpenRegister primitive later, not because a declarative rule
  wouldn't fit; the migration note is what ADR-031 asks for on any
  imperative exception.

## Risks / Trade-offs

- [Risk] `LoggingPushSender` is not a real transport → [Mitigation] the
  interface seam is the point (proposal.md Scope); a real Web Push
  implementation is a follow-up that touches only `LoggingPushSender`'s
  replacement and the DI alias.

## Migration Plan

Additive schemas only.

## Open Questions

None.
