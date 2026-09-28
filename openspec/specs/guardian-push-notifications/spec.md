# guardian-push-notifications Specification

## Purpose
Push delivery with per-subject quiet-hours windows and an emergency
override (noodmelding) that bypasses them, closing finding 9.14 and
PA-new-1. `QuietHoursPolicy` is the single source of truth for the
quiet-hours rule so it can move to an OpenRegister primitive later with one
file changed.

## Requirements

### Requirement: A non-emergency push during quiet hours is deferred, not dropped

`PushDeliveryService::deliver()` MUST check `QuietHoursPolicy::isQuietNow()`
for the target subject before sending a non-emergency push. If the subject
is currently within their configured quiet-hours window, the push MUST be
queued as a `pendingPush` object with `deliverAfter` set to the window's end,
rather than sent immediately or dropped. A subject with no configured window
MUST use a documented default (22:00–07:00, matching the corpus convention
of a sensible night-time default) rather than never being quiet.

#### Scenario: A push outside quiet hours delivers immediately

- GIVEN a subject with quiet hours 22:00–07:00, and the current time is 14:00
- WHEN a non-emergency push is delivered to them
- THEN it is sent immediately and no `pendingPush` row is created
- @e2e exclude backend delivery-timing contract — covered by PHPUnit with an
  injected clock; no UI surface

#### Scenario: A push during quiet hours is queued, not dropped

- GIVEN a subject with quiet hours 22:00–07:00, and the current time is 23:00
- WHEN a non-emergency push is delivered to them
- THEN a `pendingPush` row is created with `deliverAfter` set to 07:00, and
  no push is sent to `PushSenderInterface` yet
- @e2e exclude backend deferral contract — pinned by
  `PushDeliveryServiceTest::testAPushDuringQuietHoursIsQueuedNotDropped`; no
  UI surface

### Requirement: An emergency push bypasses quiet hours unconditionally

`PushDeliveryService::deliver()` called with `emergency: true` (noodmelding)
MUST send immediately regardless of `QuietHoursPolicy::isQuietNow()`, and
MUST NOT create a `pendingPush` row. Every emergency send MUST be logged
with the sending staff member's own subjectRef and the resolved recipient
count.

#### Scenario: An emergency push reaches a subject in quiet hours immediately

- GIVEN a subject with quiet hours 22:00–07:00, and the current time is 23:00
- WHEN an emergency push is delivered to them
- THEN it is sent immediately, bypassing the quiet-hours check entirely
- @e2e exclude fail-open-for-safety invariant (the one deliberate exception
  to this app's usual fail-closed default) — pinned by PHPUnit; no UI surface

### Requirement: A due deferred push is delivered by the background job, an undue one is left alone

`PendingPushDeliveryJob` MUST deliver every `pendingPush` row whose
`deliverAfter` is at or before the current run time, and MUST leave every
row whose `deliverAfter` is still in the future untouched.

#### Scenario: A due pending push is delivered and removed from the queue

- GIVEN a `pendingPush` row with `deliverAfter` in the past
- WHEN the job runs
- THEN `PushSenderInterface::send()` is called for it and the row is marked
  delivered
- @e2e exclude backend job contract — pinned by
  `PendingPushDeliveryJobTest::testADuePendingPushIsDeliveredAndRemoved`; no
  UI surface

#### Scenario: A not-yet-due pending push is left alone

- GIVEN a `pendingPush` row with `deliverAfter` in the future
- WHEN the job runs
- THEN it is not delivered and remains queued
- @e2e exclude backend job contract — pinned by
  `PendingPushDeliveryJobTest::testANotYetDuePendingPushIsLeftAlone`; no UI
  surface
