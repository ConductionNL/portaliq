# Tasks: notification-preferences-per-role

## Implementation Tasks

### Task 1: `portalAccount` declares its channel opt-out
- **spec_ref**: `openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN the `portalAccount` schema WHEN read THEN it declares a `notificationChannels` object property, described as fail-open (a missing key means opted in)
- [x] Implement
- [x] Test (schema-only; validated by `openspec validate` and by the PHPUnit tests in Task 2/3 seeding the new field)

### Task 2: Self-service sets the account's own opt-out
- **spec_ref**: `openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch`
- **files**: `lib/Service/Identity/PortalSelfServiceService.php`, `lib/Controller/PortalAccountSelfController.php`, `tests/Unit/Service/Identity/PortalSelfServiceServiceTest.php`, `tests/Unit/Controller/PortalAccountSelfControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a bearer's own account WHEN `PATCH /portal/api/identity/details` is called with `emailNotifications: false` THEN only that account's `notificationChannels.email` becomes `false`
  - GIVEN the same call WHEN `emailNotifications` is omitted THEN the existing field is left unchanged (matches the existing `displayName`/`email` optionality)
- [x] Implement
- [x] Test

### Task 3: Dispatch respects the opt-out without touching the failure streak
- **spec_ref**: `openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch`
- **files**: `lib/BackgroundJob/NotificationDispatchJob.php`, `tests/Unit/BackgroundJob/NotificationDispatchJobTest.php`
- **acceptance_criteria**:
  - GIVEN `notificationChannels: {"email": false}` WHEN a matching trigger fires THEN no email is sent and no `portalNotification` row is created
  - GIVEN no `notificationChannels` key (every pre-existing account) WHEN a matching trigger fires THEN dispatch proceeds exactly as before this change
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoint (existing route extended); no Newman contract exists for `PortalAccountSelfController` today, so none is added
- No UI change in this change (see proposal.md Out of Scope)
- All tests pass (`composer test`)
- No new user-facing strings (API-only)
- `openspec validate notification-preferences-per-role --strict` passes

## Skipped artifacts

- **discovery**: approach is clear from reading the existing dispatch mechanism this session; no technical uncertainty.
- **contract**: single project, existing route extended with one optional field, no new cross-project surface.
- **migration**: additive schema property, no migration needed (OpenRegister).
- **test-plan**: three small tasks with clear GIVEN/WHEN/THEN acceptance criteria already serve as the test plan.
