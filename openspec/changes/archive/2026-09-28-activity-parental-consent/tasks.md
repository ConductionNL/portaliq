# Tasks: activity-parental-consent

Kind: code. Stacked on `extracurricular-activity-offer`. Recon E, row "parental consent for an activity".

## Implementation Tasks

### Task 1: Schema fields, version bump, seed and demo data, catalogue
- **spec_ref**: `openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up`
- **files**: `lib/Settings/portaliq_register.json`, `lib/Settings/portaliq_mock_register.json`, `l10n/`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN the consent fields exist and versions are bumped
- [x] Implement
- [x] Test

### Task 2: Consent on sign-up and on opening
- **spec_ref**: `openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up`
- **files**: `lib/Service/ActivitySignupService.php`, `lib/Service/ActivityDraft.php`, `lib/Controller/ActivityController.php`, `lib/Controller/ActivityGuardianController.php`, tests
- **acceptance_criteria**:
  - GIVEN consent is required WHEN the text is missing or outdated THEN 422 consent_required and nothing written
  - GIVEN the exact text WHEN signing up THEN the record holds text, guardian and time
- [x] Implement
- [x] Test

### Task 3: Photo consent on the roster and feed
- **spec_ref**: `openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-where-photos-are-taken-the-roster-must-show-each-childs-photo-consent`
- **files**: `lib/Service/ActivityFeedReader.php`, `lib/Service/ActivitySignupService.php`, tests, `docs/operations/term-long-activities.md`
- **acceptance_criteria**:
  - GIVEN photosTaken WHEN the roster or feed is read THEN each entry carries photoConsent from photoConsentGranted()
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate activity-parental-consent` passes, diff checks green, `composer check:strict` run once (its only red is the 29 inherited PHPUnit errors)

## Quality checklist

- PHPUnit for every changed rule; register tests updated
- English and Dutch catalogue values for new schema strings; `npm run l10n:build`
