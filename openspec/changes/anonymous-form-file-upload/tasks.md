# Tasks: anonymous-form-file-upload

- [x] **T1**: `FormSubmitActions` declares a form's file fields (also in a group) as normalised file configs; an `accept` line is read as a list
  - Files: lib/Portal/FormSubmitActions.php
  - Test: PHPUnit `FormSubmitActionsTest::testAFileFieldDeclaresItsFileConfig`
- [x] **T2**: `FormUploadGrants` (+ `FormUploadGrantsInterface`, alias in `ContractRegistrar`): mint, grant, use; hash as key, thirty minutes, one file per field or ten
  - Files: lib/Service/Intake/FormUploadGrants.php, lib/Kernel/Contracts/FormUploadGrantsInterface.php, lib/Service/ContractRegistrar.php
  - Test: PHPUnit `FormUploadGrantsTest` (4 tests)
- [x] **T3**: the create answer of a form with file fields carries `uploadToken`, minted for the destination object
  - Files: lib/Controller/ContributionController.php (`uploadToken()`)
  - Test: PHPUnit `ContributionControllerLandingDestinationTest::testAFormWithAFileFieldAnswersAnUploadToken`, `testAFormWithoutAFileFieldMintsNoToken`
- [x] **T4**: `POST /portal/api/form-upload/{field}`: token, field limits, use, attach tagged with the field
  - Files: lib/Controller/FormUploadController.php, appinfo/routes.php
  - Test: PHPUnit `FormUploadControllerTest` (6 tests)
- [x] **T5**: the `file` type in the palette and FormBlock: input, checks before sending, upload after the submit, the "not every file arrived" line
  - Files: src/lib/formFieldTypes.js, src/site/lib/formFiles.js, src/site/lib/formSubmission.js, src/site/components/FormBlock.vue
  - Test: `node --test tests/form-file-upload.spec.mjs` (`check:form-file-upload`, in `check:specs`), `tests/form-widget-fields.spec.mjs`
- [x] **T6**: e2e `tests/e2e/anonymous-form-file-upload.spec.ts`: the upload route refuses without a token (written, runs in nightly)
- [ ] **T7**: Live: a visitor sends a landing-page form with a photo; the destination object holds the file tagged with its field; a second upload with the same token is refused (live lane)
