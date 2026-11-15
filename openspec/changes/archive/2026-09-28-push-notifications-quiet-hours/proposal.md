---
kind: code
---

# Proposal: push-notifications-quiet-hours

## Summary

Adds push delivery with per-subject quiet-hours windows and an emergency
override (noodmelding) that bypasses them, closing finding 9.14 and
PA-new-1 (`change-plan.md` portaliq row `push-notifications-quiet-hours`).

## Motivation

Every corpus competitor with quiet hours ships this pairing: Kwieb's "Niet
storen" (staff set daily do-not-disturb windows) plus a dedicated
"Noodmelding" that bypasses it; Social Schools' "Werktijden instellen" (push
only 08:00-16:30); Hoy's per-day notification schedule with an "important
messages" override; Parnassys Parro's "Stiltemodus" shown to parents.
learniq's own `LearniqNotificationSettings.vue` has per-user preferences but
no quiet-hours mechanism at all (`grep -rn "quiet hours" lib/` returns zero
hits, confirmed in `tier-b-and-sibling.md`).

## Affected Projects

- [x] Project: `portaliq` — new `pushSubscription`/`notificationQuietHours`/
  `pendingPush` schemas, a `QuietHoursPolicy` (the single source of truth),
  a `PushDeliveryService` with an emergency bypass, a deferred-delivery
  background job.

## Scope

### In Scope

- `QuietHoursPolicy`: the ONE service that decides whether a subject
  (guardian or staff, by subjectRef) is currently in their configured quiet
  window — kept in one service because "the quiet-hours primitive may later
  move to OpenRegister" (brief), so the rule must have exactly one call site
  to redirect later.
- `PushDeliveryService::deliver()`: a non-emergency push during quiet hours
  is DEFERRED (queued, not dropped) until the window ends; an emergency
  (noodmelding) push bypasses quiet hours unconditionally and delivers
  immediately.
- A `PendingPushDeliveryJob` background job that delivers due deferred
  pushes.
- Guardian and staff self-service: subscribe/unsubscribe a push endpoint,
  get/set own quiet-hours window.
- A staff-only emergency broadcast endpoint targeting a school/group.

### Out of Scope

- The actual Web Push transport (VAPID-signed delivery to a browser's push
  service) — `PushSenderInterface` names the seam and ships
  `LoggingPushSender` as the first implementation (an honest interim, the
  same "design against an interface, ship a minimal first implementation"
  pattern `guardian-direct-messages` (same lane) already uses); provisioning
  VAPID keys is an admin-settings concern for a follow-up change.
- Per-channel (email vs push vs in-app) preference granularity — finding
  14.4 / `notification-preferences-per-role`, a separate, not-yet-scheduled
  change.
- Digest/batching of deferred pushes — each deferred push is delivered
  individually when its window ends; combining several into one digest
  notification is a follow-up refinement, not named by 9.14/PA-new-1.

## Approach

`QuietHoursPolicy` reads a per-subject `notificationQuietHours` object
(falling back to a documented default window when none is configured —
fail-closed towards NOT disturbing, per every corpus competitor's default-on
framing). `PushDeliveryService` is the ONLY caller of `PushSenderInterface`
and the ONLY caller of `QuietHoursPolicy` for the send decision, so a future
feature that wants to push a notification (e.g. a published news item) has
exactly one method to call and cannot bypass the quiet-hours rule by
accident.

## New Dependencies

None (Web Push transport is explicitly deferred — see Scope).

## Impact

- `lib/Settings/portaliq_register.json` — new schemas `pushSubscription`,
  `notificationQuietHours`, `pendingPush`.
- `lib/Service/Notifications/QuietHoursPolicy.php`,
  `PushSenderInterface.php`, `LoggingPushSender.php`,
  `PushDeliveryService.php`.
- `lib/Controller/PushSubscriptionController.php`,
  `QuietHoursController.php`, `EmergencyPushController.php`.
- `lib/BackgroundJob/PendingPushDeliveryJob.php`.
- `appinfo/routes.php`, `appinfo/info.xml` (background job registration).

## Cross-Project Dependencies

None required to ship; `decisions.md`/`tier-b-and-sibling.md` name
OpenRegister's `notification-quiet-hours-primitive` as a possible future
home for `QuietHoursPolicy`'s rule — this change's single-call-site
discipline is exactly what makes that migration a one-file change later.

## Risks

### Risk 1: An emergency broadcast is misused as a routine channel
**Severity:** Medium — **Mitigation:** `EmergencyPushController` is
`#[NoAdminRequired]` (a Nextcloud-authenticated staff session) and every
emergency send is logged with the sending staff member's own subjectRef and
the resolved recipient count, so misuse is attributable and auditable —
mirroring this app's existing `AuditTrailService` convention.

### Risk 2: A deferred push silently never delivers
**Severity:** Medium — **Mitigation:** `PendingPushDeliveryJob` runs on
every background-job tick (mirrors `TrafficReportJob`'s existing cadence in
this app) and a dedicated PHPUnit test pins that a due pending push is
picked up and delivered, and an undue one is left alone.

## Rollback Strategy

Additive schemas; disabling the feature is removing the new routes and
unregistering the background job. No migration needed to roll back.

## Open Questions

None.
