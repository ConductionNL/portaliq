# Tasks: push-notifications-quiet-hours

## Implementation Tasks

### Task 1: Register schemas and seed data
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/design.md#seed-data`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the register file WHEN validated as JSON THEN `pushSubscription`, `notificationQuietHours`, `pendingPush`, `guardianAudienceFixture` schemas and seed fixture rows exist
- [x] Implement
- [x] Test

### Task 2: QuietHoursPolicy (the single source of truth)
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped`
- **files**: `lib/Service/Notifications/QuietHoursPolicy.php`
- **acceptance_criteria**:
  - GIVEN a subject with a configured window WHEN the current time is inside it THEN isQuietNow is true, including a window that wraps midnight
  - GIVEN a subject with no configured window WHEN checked THEN the documented default (22:00-07:00) applies
- [x] Implement
- [x] Test

### Task 3: PushSenderInterface + LoggingPushSender + DI binding
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages`
- **files**: `lib/Service/Notifications/PushSenderInterface.php`, `lib/Service/Notifications/LoggingPushSender.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN PushSenderInterface is resolved THEN it returns a LoggingPushSender instance
- [x] Implement
- [x] Test

### Task 4: PushDeliveryService + PendingPushService
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally`
- **files**: `lib/Service/Notifications/PushDeliveryService.php`, `lib/Service/Notifications/PendingPushService.php`
- **acceptance_criteria**:
  - GIVEN a subject in quiet hours WHEN a non-emergency push is delivered THEN it is queued, not sent, and not dropped
  - GIVEN emergency: true WHEN delivered during quiet hours THEN it sends immediately regardless
- [x] Implement
- [x] Test

### Task 5: PendingPushDeliveryJob
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone`
- **files**: `lib/BackgroundJob/PendingPushDeliveryJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a due pendingPush row WHEN the job runs THEN it is delivered and marked delivered
  - GIVEN a not-yet-due row WHEN the job runs THEN it is left alone
- [x] Implement
- [x] Test

### Task 6: PushSubscriptionController + QuietHoursGuardianController
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/design.md#api-design`
- **files**: `lib/Controller/PushSubscriptionController.php`, `lib/Controller/QuietHoursGuardianController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no resolved subject WHEN any guardian endpoint is called THEN 401
- [x] Implement
- [x] Test

### Task 7: QuietHoursStaffController + EmergencyPushController
- **spec_ref**: `openspec/changes/push-notifications-quiet-hours/design.md#api-design`
- **files**: `lib/Controller/QuietHoursStaffController.php`, `lib/Controller/EmergencyPushController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a staff session WHEN an emergency push is sent to a target THEN every matching guardian receives it immediately and the response reports the recipient count
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests
- `openspec validate push-notifications-quiet-hours` passes
- SPDX headers on every new PHP file

## Blocked / deferred

- Real Web Push transport (VAPID-signed delivery): `LoggingPushSender` is
  the interim implementation; provisioning VAPID keys is an admin-settings
  concern for a follow-up change (see proposal.md Scope).
- No UI ships in this change (API-only).

## Verification
- [x] All tasks checked off
- [x] `openspec validate push-notifications-quiet-hours --strict` passes
- [x] Diff-scoped gates green on touched files (php -l, phpcs, phpmd, phpunit --filter)
