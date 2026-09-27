# Tasks: inbox-notifications-and-preferences

## The declaration

- [ ] **T01**: Accept change rule objects in a contribution's `notifications` next to plain strings; drop and log a rule whose collection is foreign, not default-scoped, or whose field is not projected (REQ-NAP-001)
  - PHPUnit `NotificationRuleNormaliserTest::testKeepsAWellFormedRule`, `::testDropsARuleOnAViaCollection`, `::testDropsAnUnprojectedField`, `::testPlainStringsStillPass`

## Noticing the change

- [ ] **T02**: `PortalRecordChangeListener` on `ObjectUpdatedEvent`: match the rule, compare old and new, resolve the account, write the `portalMessage` with `recordLink`, dispatch (REQ-NAP-002)
  - PHPUnit `PortalRecordChangeListenerTest` built with a real `ObjectUpdatedEvent` and real `ObjectEntity` objects, not a mock event: `::testStatusChangeWritesAMessageAndDispatches`, `::testUnchangedFieldDoesNothing`, `::testMissingOldObjectDoesNothing`, `::testFailureNeverThrows`
- [ ] **T03**: `PortalWriteContext` set by `PortalObjectWriter` and `PortalFileWriter`; the listener skips events raised inside it (REQ-NAP-003)
  - PHPUnit `PortalRecordChangeListenerTest::testResidentsOwnWriteIsNotReported`
- [ ] **T04**: The same listener on `ObjectCreatedEvent` for `kind: inbox` collections of apps declaring `message.created`, skipping `portalMessage` (REQ-NAP-004)
  - PHPUnit `PortalRecordChangeListenerTest::testCaseAppMessageDispatches`, `::testPortalMessageIsNotDispatchedTwice`
- [ ] **T05**: Add `recordLink` to `portalMessage` in `lib/Settings/portaliq_register.json` (REQ-NAP-005)
  - Manual check: import the register and create a message with a `recordLink` through OpenRegister

## Sending

- [ ] **T06**: `NotificationDispatchJob` picks its text by kind and links with `PortalDeepLinkBuilder::forRecord()` when the trigger carried a record (REQ-NAP-005, REQ-NAP-006)
  - PHPUnit `NotificationDispatchJobTest::testChangeRuleTextCarriesNoCaseContent`, `PortalDeepLinkBuilderTest::testForRecordAddsTheFragment`
- [ ] **T07**: Per-kind e-mail and push choices in the job; push through `PushDeliveryService::deliver()`, logged with `channel` `push`, added to the `portalNotification.channel` enum (REQ-NAP-007)
  - PHPUnit `NotificationDispatchJobTest::testKindEmailOffSendsNoEmail`, `::testKindPushOnSendsAPush`, `::testGlobalEmailOptOutStillWins`

## Preferences

- [ ] **T08**: `portalAccount.notificationPreferences` in the register; `GET` and `PATCH /portal/api/identity/notification-preferences` for the caller's own account only (REQ-NAP-007)
  - PHPUnit `PortalAccountSelfControllerTest::testPreferencesAreTheCallersOwn`, `::testUnknownKindIsIgnored`, `::testPushAvailableFollowsTheSubscription`

## The screens

- [ ] **T09**: `App.jsx` reads and strips `#open=`, keeps it through sign-in in `sessionStorage`, and opens the record's page and row (REQ-NAP-005)
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: a signed-out resident follows a case link, signs in with dev-login, and lands on that case
- [ ] **T10**: "Open" on an inbox message with `recordLink` (REQ-NAP-005)
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: a handler changes the case status through the OpenRegister API, the resident sees the message and opens the case
- [ ] **T11**: The "Notification settings" section on `InboxPage.jsx` (REQ-NAP-008)
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: switching e-mail off for case changes survives a reload

## Docs and strings

- [ ] **T12**: Dutch and English strings for the message, the e-mail, the settings section and its confirmation; the contract docs page gains the change rule and advice on which fields to declare
- [ ] **T13**: `openspec validate inbox-notifications-and-preferences --strict`
