# Tasks: activity-offer-contract-fix

Kind: code. Fixes the #746 contract against shillinq #1704's contract; D19, D30.

## Implementation Tasks

### Task 1: The shillinq raise adapter
- **spec_ref**: `openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference`
- **files**: `lib/Service/ShillinqContributionRaiser.php`, `tests/Unit/Service/ShillinqContributionRaiserTest.php`
- **acceptance_criteria**:
  - GIVEN no shillinq WHEN raising THEN `shillinq_unavailable`; each shillinq exception maps to one answer
- [x] Implement
- [x] Test

### Task 2: Raise and write the references
- **spec_ref**: `openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference`
- **files**: `lib/Service/ActivityContributionService.php`, `tests/Unit/Service/ActivityContributionServiceTest.php`
- **acceptance_criteria**:
  - GIVEN confirmed places without a reference WHEN raised THEN each is sent once and gets the returned id
  - GIVEN a skipped result WHEN raised THEN the standing id is written; a guardian without contact details is reported
- [x] Implement
- [x] Test

### Task 3: The staff endpoint
- **spec_ref**: `openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference`
- **files**: `lib/Controller/ActivityController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ActivityControllerTest.php`
- **acceptance_criteria**:
  - GIVEN each refusal WHEN staff call the endpoint THEN 403, 404, 422, 400, 503, 502 as specified
- [x] Implement
- [x] Test

### Task 4: Contract, descriptions and docs
- **spec_ref**: `openspec/changes/activity-offer-contract-fix/proposal.md`
- **files**: `openspec/changes/extracurricular-activity-offer/contract.md`, `lib/Settings/portaliq_register.json`, `tests/Unit/Settings/PortaliqRegisterConfigTest.php`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `docs/operations/term-long-activities.md`
- **acceptance_criteria**:
  - GIVEN the contract WHEN read THEN portaliq writes paymentRequestRef, the activity is the subject, the child the beneficiary
- [x] Implement

## Verification
- [x] `openspec validate activity-offer-contract-fix`, diff-scoped checks, `composer check:strict` once, `npm run lint`, `npm run format`, `check:schema-l10n`, `check:register`, hydra gates

## Quality checklist

- No amount stored in portaliq.
- Register and schema versions bumped with the description change; catalogue keys for both new strings.
