# Tasks: assignment-portal-file-upload

Kind: code. Learniq round 2, recon C, change row `assignment-portal-file-upload`; decision D15.

## Implementation Tasks

### Task 1: Keep the file field keys in the normaliser
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field`
- **files**: `lib/Contribution/ActionConfigNormaliser.php`, `tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php`
- **acceptance_criteria**:
  - GIVEN a create action file config WHEN normalised THEN type, multiple, accept and maxSizeMb survive sanitised
  - GIVEN an endpoint action or an unknown type WHEN normalised THEN no type is kept
- [x] Implement
- [x] Test

### Task 2: Strip file fields from every write body
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-file-field-must-never-be-written-from-a-request-body`
- **files**: `lib/Controller/ContributionController.php`, `tests/Unit/Controller/ContributionControllerFileFieldTest.php`
- **acceptance_criteria**:
  - GIVEN a declared file field WHEN a create, anonymous create or update body carries it THEN the writer never sees it
- [x] Implement
- [x] Test

### Task 3: The file field policy
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own`
- **files**: `lib/Service/PortalFileFieldPolicy.php`, `tests/Unit/Service/PortalFileFieldPolicyTest.php`
- **acceptance_criteria**:
  - GIVEN accept, size, create window and a current value WHEN checked THEN the refusal or merged value is as specified
- [x] Implement
- [x] Test

### Task 4: The upload endpoint
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own`
- **files**: `lib/Controller/PortalFieldFileController.php`, `appinfo/routes.php`, `tests/Unit/Controller/PortalFieldFileControllerTest.php`
- **acceptance_criteria**:
  - GIVEN an owned object and a declared file field WHEN a file is uploaded THEN it is attached and its id written into the field
  - GIVEN any refusal WHEN uploading THEN the answer comes before any attach
- [x] Implement
- [x] Test

### Task 5: The file picker in the portal form
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-the-generic-portal-form-must-render-a-file-field-as-a-file-picker`
- **files**: `src/portal/lib/fileFieldSubmit.js`, `src/portal/lib/portalApi.js`, `src/portal/components/SchemaForm.jsx`, `src/portal/components/PageView.jsx`, `src/portal/i18n/en.json`, `src/portal/i18n/nl.json`, `tests/schema-form-file-field.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN a file field WHEN the form renders THEN it is a labelled file input
  - GIVEN picked files WHEN the form is saved THEN it creates, uploads each file and names any that failed
- [x] Implement
- [x] Test

### Task 6: Contract documentation and spec index
- **spec_ref**: `openspec/changes/assignment-portal-file-upload/contract.md`
- **files**: `openspec/specs/portal-contribution-contract/spec.md`, `docs/` contribution contract page if present
- **acceptance_criteria**:
  - GIVEN a leaf app author WHEN they read the docs THEN the file field keys and the upload endpoint are described
- [x] Implement

## Verification
- [ ] `openspec validate assignment-portal-file-upload` passes, diff checks green, `composer check:strict` run once

## Quality checklist

- PHPUnit for the normaliser, the strip, the policy and the controller
- `node --test` for the form render and the submit module
- English and Dutch strings for every new portal string (`src/portal/i18n/`)
- No Playwright run: the live attach is blocked by portaliq#29 (see test-plan.md)
