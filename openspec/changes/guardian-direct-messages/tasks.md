# Tasks: guardian-direct-messages

## Implementation Tasks

### Task 1: Register schemas
- **spec_ref**: `openspec/changes/guardian-direct-messages/design.md#seed-data`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the register file WHEN validated as JSON THEN `messageThread`, `message`, `groupStaffFixture`, `guardianAudienceFixture` schemas and seed fixture rows exist
- [x] Implement
- [x] Test

### Task 2: GroupStaffFixtureReader + shared GuardianAudienceFixtureReader
- **spec_ref**: `openspec/changes/guardian-direct-messages/design.md#participation-model`
- **files**: `lib/Service/GroupStaffFixtureReader.php`, `lib/Service/GuardianAudienceFixtureReader.php`
- **acceptance_criteria**:
  - GIVEN a groupRef with a fixture row WHEN resolved THEN its staffRefs are returned; absent row THEN empty
- [x] Implement
- [x] Test

### Task 3: GuardianMessagingLeafInterface + InAppMessagingLeaf
- **spec_ref**: `openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group`
- **files**: `lib/Service/Messaging/GuardianMessagingLeafInterface.php`, `lib/Service/Messaging/InAppMessagingLeaf.php`, `lib/Service/Messaging/MessageThreadAccessGuard.php` (authorization, split out to keep `InAppMessagingLeaf`'s own phpmd `ExcessiveClassComplexity` under the 50 threshold), `lib/Service/Messaging/MessageStore.php` (OR persistence plumbing, same reason)
- **acceptance_criteria**:
  - GIVEN a staff member outside the guardian's reach WHEN a direct thread is requested THEN it is refused, no thread created
  - GIVEN a non-participant WHEN they read/post/mark-read THEN refused identically to a non-existent thread
- [x] Implement
- [x] Test

### Task 4: MessageGuardianController
- **spec_ref**: `openspec/changes/guardian-direct-messages/design.md#api-design`
- **files**: `lib/Controller/MessageGuardianController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no resolved subject WHEN any endpoint is called THEN 401
  - GIVEN a valid create/post/read request WHEN handled THEN the leaf's result decides the response code
- [x] Implement
- [x] Test

### Task 5: MessageStaffController
- **spec_ref**: `openspec/changes/guardian-direct-messages/design.md#api-design`
- **files**: `lib/Controller/MessageStaffController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a staff member teaching a group WHEN they create a group thread THEN it is created; otherwise refused
- [x] Implement
- [x] Test

### Task 6: DI binding for the messaging leaf interface
- **spec_ref**: `openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN `GuardianMessagingLeafInterface` is resolved THEN it returns an `InAppMessagingLeaf` instance
- [x] Implement
- [x] Test (verified via container-wired controller tests, no dedicated Application test in this app's existing suite)

## Quality checklist

- All new business logic covered by PHPUnit unit tests
- `openspec validate guardian-direct-messages` passes
- SPDX headers on every new PHP file

## Blocked / deferred

- No UI ships in this change (API-only, per proposal.md Scope) — the
  per-group staff inbox VIEW is `teacher-inbox-per-group`, stacked on this
  change.

## Verification
- [x] All tasks checked off
- [x] `openspec validate guardian-direct-messages --strict` passes
- [x] Diff-scoped gates green on touched files (php -l, phpcs, phpmd, phpunit --filter)
