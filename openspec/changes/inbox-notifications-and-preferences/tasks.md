# Tasks: inbox-notifications-and-preferences

## The declaration

- [x] **T01**: Accept change rule objects in a contribution's `notifications` next to plain strings; drop and log a rule whose collection is foreign, not default-scoped, or whose field is not projected (REQ-NAP-001) Done: lib/Contribution/NotificationRuleNormaliser.php, wired in PortalContributionRegistry (drops logged with the app); dispatch matches a rule object's key.
  - PHPUnit `NotificationRuleNormaliserTest::testKeepsAWellFormedRule`, `::testDropsARuleOnAViaCollection`, `::testDropsAnUnprojectedField`, `::testPlainStringsStillPass`

## Noticing the change

- [x] **T02**: `PortalRecordChangeListener` on `ObjectUpdatedEvent`: match the rule, compare old and new, resolve the account, write the `portalMessage` with `recordLink`, dispatch (REQ-NAP-002) Done: lib/Listener/PortalRecordChangeListener.php over lib/Service/Notifications/PortalChangeRuleIndex.php (built once per request from PortalContributionRegistry::contributionsForEveryAudience(), register/schema ids mapped to slugs with OpenRegister's getIdToSlugMap). Tests build the real events; tests/bootstrap.php autoloads OpenRegister from PORTALIQ_OPENREGISTER_LIB outside a container.
  - PHPUnit `PortalRecordChangeListenerTest` built with a real `ObjectUpdatedEvent` and real `ObjectEntity` objects, not a mock event: `::testStatusChangeWritesAMessageAndDispatches`, `::testUnchangedFieldDoesNothing`, `::testMissingOldObjectDoesNothing`, `::testFailureNeverThrows`
- [x] **T03**: `PortalWriteContext` set by `PortalObjectWriter` and `PortalFileWriter`; the listener skips events raised inside it (REQ-NAP-003) Done: lib/Service/PortalWriteContext.php, set around every save in PortalObjectWriter and the attach in PortalFileWriter. The three staff CMS controllers that build a writer by hand (Event, News, Newsletter) write outside it; they are staff writes, which is what the listener reports.
  - PHPUnit `PortalRecordChangeListenerTest::testResidentsOwnWriteIsNotReported`
- [x] **T04**: The same listener on `ObjectCreatedEvent` for `kind: inbox` collections of apps declaring `message.created`, skipping `portalMessage` (REQ-NAP-004) Done in the same listener.
  - PHPUnit `PortalRecordChangeListenerTest::testCaseAppMessageDispatches`, `::testPortalMessageIsNotDispatchedTwice`
- [x] **T05**: Add `recordLink` to `portalMessage` in `lib/Settings/portaliq_register.json` (REQ-NAP-005) Done: portalMessage.recordLink (0.5.0), register 0.39.0; the listener test validates the written message against this schema with Opis. Manual import check not run (no instance in this lane).
  - Manual check: import the register and create a message with a `recordLink` through OpenRegister

## Sending

- [x] **T06**: `NotificationDispatchJob` picks its text by kind and links with `PortalDeepLinkBuilder::forRecord()` when the trigger carried a record (REQ-NAP-005, REQ-NAP-006) Done: CHANGE_SUBJECT_KEY/CHANGE_BODY_KEY, PortalDeepLinkBuilder::forRecord().
  - PHPUnit `NotificationDispatchJobTest::testChangeRuleTextCarriesNoCaseContent`, `PortalDeepLinkBuilderTest::testForRecordAddsTheFragment`
- [x] **T07**: Per-kind e-mail and push choices in the job; push through `PushDeliveryService::deliver()`, logged with `channel` `push`, added to the `portalNotification.channel` enum (REQ-NAP-007) Done: per-kind choices; push only with a registered device; portalNotification.channel enum [email, push] (0.2.0); push failures do not count toward needsAlternativeContact.
  - PHPUnit `NotificationDispatchJobTest::testKindEmailOffSendsNoEmail`, `::testKindPushOnSendsAPush`, `::testGlobalEmailOptOutStillWins`

## Preferences

- [x] **T08**: `portalAccount.notificationPreferences` in the register; `GET` and `PATCH /portal/api/identity/notification-preferences` for the caller's own account only (REQ-NAP-007) Done: PortalSelfServiceService::notificationPreferences()/updateNotificationPreferences(), portalAccount.notificationPreferences (0.11.0).
  - PHPUnit `PortalAccountSelfControllerTest::testPreferencesAreTheCallersOwn`, `::testUnknownKindIsIgnored`, `::testPushAvailableFollowsTheSubscription`

## The screens

- [x] **T09**: `App.jsx` reads and strips `#open=`, keeps it through sign-in in `sessionStorage`, and opens the record's page and row (REQ-NAP-005) Done: src/portal/lib/openRecord.js (tests/open-record.spec.mjs), App.jsx, PageView.jsx preselect and not-in-your-list notice. Playwright file written, not run in this lane.
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: a signed-out resident follows a case link, signs in with dev-login, and lands on that case
- [x] **T10**: "Open" on an inbox message with `recordLink` (REQ-NAP-005) Done: InboxPage.jsx Open button.
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: a handler changes the case status through the OpenRegister API, the resident sees the message and opens the case
- [x] **T11**: The "Notification settings" section on `InboxPage.jsx` (REQ-NAP-008) Done: src/portal/components/NotificationSettings.jsx on InboxPage.jsx.
  - Playwright `tests/e2e/inbox-notifications-and-preferences.spec.ts`: switching e-mail off for case changes survives a reload

## Docs and strings

- [x] **T12**: Dutch and English strings for the message, the e-mail, the settings section and its confirmation; the contract docs page gains the change rule and advice on which fields to declare Done: l10n/en.json, l10n/nl.json (mail, message, schema strings), src/portal/i18n/{en,nl}.json, docs/operations/notices-on-a-case.md.
- [x] **T13**: `openspec validate inbox-notifications-and-preferences --strict` Done: openspec validate --strict.
