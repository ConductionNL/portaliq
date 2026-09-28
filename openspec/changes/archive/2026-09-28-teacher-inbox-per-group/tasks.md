# Tasks: teacher-inbox-per-group

## Implementation Tasks

### Task 1: TeacherInboxService
- **spec_ref**: `openspec/changes/teacher-inbox-per-group/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count`
- **files**: `lib/Service/TeacherInboxService.php`
- **acceptance_criteria**:
  - GIVEN a staff member's threads WHEN the inbox is built THEN group threads bucket by groupRef and direct threads bucket under `direct`, each with an accurate unread count
  - GIVEN a thread the staff member does not participate in WHEN the inbox is built THEN it never appears
- [x] Implement
- [x] Test

### Task 2: MessageStaffController::inbox() + route
- **spec_ref**: `openspec/changes/teacher-inbox-per-group/design.md#api-design`
- **files**: `lib/Controller/MessageStaffController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a staff session WHEN `GET /api/staff/messages/inbox` is called THEN the bucketed response is returned
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests
- `openspec validate teacher-inbox-per-group` passes
- SPDX headers on the new PHP file

## Verification
- [x] All tasks checked off
- [x] `openspec validate teacher-inbox-per-group --strict` passes
- [x] Diff-scoped gates green on touched files (php -l, phpcs, phpmd, phpunit --filter)
