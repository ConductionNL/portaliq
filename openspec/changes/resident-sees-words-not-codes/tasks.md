# Tasks: resident-sees-words-not-codes

- [x] **T1**: a page keeps a well-formed `group`; the site's resident menu groups pages by it across apps and falls back to the app's name
  - PHPUnit `PortalPageGroupTest`; `node --test tests/site-resident-menu.spec.mjs`
- [x] **T2**: the receipt, the notification mail and push, and the task notice and mail use `PortalNoticeLanguage`, one language each; the receipt subject loses its em-dash
  - PHPUnit `SubmissionReceiptServiceTest::testTheReceiptIsInThePortalsLanguageOnly`, `NotificationDispatchJobTest::testTheEmailIsInThePortalsLanguageOnly`, `PortalTaskDeliveryJobTest::testAnInboxNoticeIsInThePortalsLanguageOnly`, `::testAskMailIsInThePortalsLanguageOnly`
- [x] **T3**: a collection keeps well-formed `fieldConfigs.<field>.{label,valueLabels}`; the site's detail card and a column without its own labels use them
  - PHPUnit `CollectionFieldConfigsTest`; `node --test tests/value-labels.spec.mjs`
- [x] **T4**: the session endpoint and the site header never name a person by a number (subject reference, identity number, digits only)
  - PHPUnit `SessionControllerTest::testIndexNamesThePersonNeverTheReference`; `node --test tests/site-signed-in-shell.spec.mjs`
- [x] **T5**: the publication page shows summary, date, category name, theme names and documents only; the category filter names its categories
  - `node --test tests/publication-documents.spec.mjs` (`npm run check:publication-documents`)
- [x] **T6**: "My cases" shows a case's status by its public label and never a uuid
  - `node --test tests/my-cases-page.spec.mjs` (`npm run check:my-cases-page`)
