# Tasks: parent-polls

## Implementation Tasks

### Task 1: `portalPoll` and `portalPollResponse` schemas
- **spec_ref**: `openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN it declares `portalPoll` (question, options[], audience, organisation, closesAt, createdBy, createdAt) and `portalPollResponse` (pollId, subjectRef, optionId, respondedAt)
- [x] Implement
- [x] Test (schema-only; validated by `openspec validate` and by the PHPUnit tests in Task 2/3)

### Task 2: Staff creates a poll
- **spec_ref**: `openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation`
- **files**: `lib/Service/PollService.php`, `lib/Controller/PollController.php`, `appinfo/routes.php`, `tests/Unit/Service/PollServiceTest.php`, `tests/Unit/Controller/PollControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a staff user WHEN `POST /apps/portaliq/api/polls` is called with a question and two options THEN a `portalPoll` is created with `createdBy` the calling user
  - GIVEN the same call WHEN only one option is given THEN the request is refused and nothing is created
- [x] Implement
- [x] Test

### Task 3: A portal subject reads and answers their own polls
- **spec_ref**: `openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-portal-subject-sees-only-polls-for-their-own-audience-and-organisation`
- **files**: `lib/Service/PollService.php`, `lib/Controller/PollController.php`, `appinfo/routes.php`, `tests/Unit/Service/PollServiceTest.php`, `tests/Unit/Controller/PollControllerTest.php`
- **acceptance_criteria**:
  - GIVEN polls across audiences and organisations WHEN a bearer calls `GET /portal/api/polls` THEN only polls matching their own audience AND organisation are returned, each with only their own response
  - GIVEN a subject who already answered WHEN they `respond` again with a different option THEN exactly one response row exists, updated
  - GIVEN a closed poll WHEN a subject calls `respond` THEN the request is refused and nothing is written
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No Newman contract exists for this app's staff/portal routes today, so none is added (matches `notification-preferences-per-role`'s precedent)
- No UI change in this change (see proposal.md Out of Scope)
- All tests pass (`composer test`)
- No new user-facing strings (API-only)
- `openspec validate parent-polls --strict` passes

## Skipped artifacts

- **discovery**: approach follows `change-proposal-queue`'s established shape; no technical uncertainty.
- **contract**: single project, no cross-project consumer.
- **migration**: new schemas, no migration needed (OpenRegister).
- **test-plan**: three small tasks with clear GIVEN/WHEN/THEN acceptance criteria already serve as the test plan.
