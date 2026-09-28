# Test Plan: extracurricular-activity-offer

No browser runs: this change ships API only, and the lane may not touch the
shared instance. Every scenario is pinned in PHPUnit.

## Test Cases

### TC-1: Places are the lower of capacity and supervision
- **spec_ref**: `openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision`
- **type**: regression
- **steps**: compute places for capacity 20 with 2 supervisors at 8; for no ratio; for duplicate supervisor refs
- **expected result**: 16; 20; duplicates counted once
- **test command**: `vendor/bin/phpunit --filter ActivitySignupServiceTest`

### TC-2: Opening needs a place
- **spec_ref**: same requirement
- **type**: api
- **steps**: open a draft with ratio 8 and no supervisors; open one with a supervisor
- **expected result**: 422 `no_places`, nothing written; then 200 `open`
- **test command**: `vendor/bin/phpunit --filter ActivityControllerTest`

### TC-3: Confirm, then waitlist, then full
- **spec_ref**: `...#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full`
- **type**: api
- **steps**: sign up into the last place, then again with waitlist on, then with waitlist off
- **expected result**: confirmed; waitlisted position 1; `activity_full`
- **test command**: `vendor/bin/phpunit --filter ActivitySignupServiceTest`

### TC-4: Refusals write nothing
- **spec_ref**: same requirement
- **type**: security
- **steps**: another guardian's child, activity outside the audience, closed activity, passed deadline, duplicate sign-up, unreadable sign-ups
- **expected result**: not found, not found, closed, closed, already signed up, unavailable; `save` never called
- **test command**: `vendor/bin/phpunit --filter 'ActivitySignupServiceTest|ActivityGuardianControllerTest'`

### TC-5: Promotion order
- **spec_ref**: `...#requirement-a-freed-place-must-go-to-the-child-who-waited-longest`
- **type**: regression
- **steps**: withdraw a confirmed child; withdraw a waitlisted child; raise supervisors
- **expected result**: oldest waitlisted confirmed; nobody promoted; two promoted in order
- **test command**: `vendor/bin/phpunit --filter ActivitySignupServiceTest`

### TC-6: Attendance
- **spec_ref**: `...#requirement-staff-must-be-able-to-mark-attendance-per-session`
- **type**: api
- **steps**: mark, re-mark, unknown session, waitlisted child, bad status
- **expected result**: created; updated in place; three 422s with nothing written
- **test command**: `vendor/bin/phpunit --filter ActivityAttendanceServiceTest`

### TC-7: Register
- **spec_ref**: first requirement
- **type**: regression
- **steps**: register tests and gate checkers
- **expected result**: versions bumped, read rules present, no broad write, demo data valid, schema strings translated
- **test command**: `vendor/bin/phpunit --filter 'PortaliqRegisterConfigTest|RegisterAuthorizationTest'`, `npm run check:schema-l10n`

## Coverage Summary

All four requirements: TC-1 to TC-7.

## Out of Scope

Screens (none ship), payment request creation (shillinq), consent (next change).
