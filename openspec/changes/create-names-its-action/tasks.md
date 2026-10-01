# Tasks: create-names-its-action

- [x] **T1**: The create path matches the action by its id, refuses an unknown id, and refuses an ambiguous create without one
  - PHPUnit `ContributionControllerTest::testCreateWritesThroughTheActionItNames`, `::testCreateNamingAnUnknownActionIsRefused`, `::testCreateWithoutAnIdBetweenTwoActionsIsRefusedNotGuessed`, `::testCreateWithoutAnIdAndOneActionStillWorks`
- [x] **T2**: The portal frontend sends the action id on every create
  - `node --test tests/create-names-its-action.spec.mjs` (`check:create-names-its-action`)
- [x] **T3**: The anonymous create path matches the landing-page form by its action id, and the site's landing-page form sends it
  - PHPUnit `ContributionControllerTest::testAnonymousCreateWritesThroughTheFormItNames`, `::testAnonymousCreateNamingNoFormOrAnUnknownOneIsRefused`, `::testAnonymousCreateNamingASignedInActionIsRefused`
  - `node --test tests/create-names-its-action.spec.mjs` (site: FormBlock names `submit-{formId}`; no create in src/shared or src/site bypasses the id)
