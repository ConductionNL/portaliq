# Tasks: acting-for-and-access-page-read-in-dutch

- [x] **T1**: `src/site/pages/e/translate.js` (`accountPageTranslator`, `withAccountStrings`) and `pages/e/index.js` wrap every account page; `pages/inbox/translate.js` gains `withBundles`
  - node `tests/account-pages-language.spec.mjs` (4 tests), wired into `check:specs`
- [x] **T2**: example set and demo mandates are labelled with the party's name; the `portalMandate.label` description says so (en and nl catalogue)
- [x] **T3**: `PortalAccessRequestService::grant()` labels the mandate with the party as the asker named it
  - PHPUnit `PortalAccessRequestServiceTest::testAGrantRecordsTheMandateThatOpensTheCases`
