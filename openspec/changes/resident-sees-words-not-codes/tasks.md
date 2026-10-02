# Tasks: resident-sees-words-not-codes

- [x] **T1**: a page keeps a well-formed `group`; the site's resident menu groups pages by it across apps and falls back to the app's name
  - PHPUnit `PortalPageGroupTest`; `node --test tests/site-resident-menu.spec.mjs`
- [x] **T2**: the receipt, the notification mail and push, and the task notice and mail use `PortalNoticeLanguage`, one language each; the receipt subject loses its em-dash
  - PHPUnit `SubmissionReceiptServiceTest::testTheReceiptIsInThePortalsLanguageOnly`, `NotificationDispatchJobTest::testTheEmailIsInThePortalsLanguageOnly`, `PortalTaskDeliveryJobTest::testAnInboxNoticeIsInThePortalsLanguageOnly`, `::testAskMailIsInThePortalsLanguageOnly`
- [x] **T3**: a collection keeps well-formed `fieldConfigs.<field>.{label,valueLabels}`; the site's detail card and a column without its own labels use them
  - PHPUnit `CollectionFieldConfigsTest`; `node --test tests/value-labels.spec.mjs`
