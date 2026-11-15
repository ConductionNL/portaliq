# Tasks: extracurricular-activity-offer

Kind: code. Learniq round 2, recon E, change row `extracurricular-activity-offer`; decisions D1, D19.

## Implementation Tasks

### Task 1: Schemas, version bump, seed and demo data, catalogue
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `l10n/`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN activityOffer, activitySignup and activityAttendance exist at 0.1.0 and the register is 0.34.0
  - GIVEN the gates WHEN run THEN each new schema has three valid demo objects and every schema string has a catalogue key
- [x] Implement
- [x] Test

### Task 2: The store
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service`
- **files**: `lib/Service/ActivityStore.php`, `tests/Unit/Service/ActivityStoreTest.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister fails WHEN rows are read THEN null is returned, never an empty list
- [x] Implement
- [x] Test

### Task 3: Feed reader
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full`
- **files**: `lib/Service/ActivityFeedReader.php`, `tests/Unit/Service/ActivityFeedReaderTest.php`
- **acceptance_criteria**:
  - GIVEN a guardian WHEN the feed is read THEN only non-draft in-audience activities appear, with places left and only their own children's sign-ups
- [x] Implement
- [x] Test

### Task 4: Sign-up, waiting list, withdrawal, promotion, roster
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest`
- **files**: `lib/Service/ActivitySignupService.php`, `tests/Unit/Service/ActivitySignupServiceTest.php`
- **acceptance_criteria**:
  - GIVEN places WHEN signing up THEN confirmed, waitlisted or full as specified
  - GIVEN a withdrawal or more supervisors WHEN applied THEN waitlisted children are confirmed in order
- [x] Implement
- [x] Test

### Task 5: Attendance
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session`
- **files**: `lib/Service/ActivityAttendanceService.php`, `tests/Unit/Service/ActivityAttendanceServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a confirmed child and a declared session WHEN marked twice THEN one row holds the last status
- [x] Implement
- [x] Test

### Task 6: Controllers and routes
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/contract.md`
- **files**: `lib/Controller/ActivityController.php`, `lib/Controller/ActivityGuardianController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ActivityControllerTest.php`, `tests/Unit/Controller/ActivityGuardianControllerTest.php`
- **acceptance_criteria**:
  - GIVEN each route WHEN called THEN status codes match contract.md; staff methods call the guard first
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate extracurricular-activity-offer` passes, diff checks green, `composer check:strict` run once (its only red is the 29 inherited PHPUnit errors)

## Quality checklist

- PHPUnit for every new class; register tests updated
- `npm run check:schema-l10n` shows no growth over development's 11
- English and Dutch catalogue values for every new schema string, then `npm run l10n:build`
