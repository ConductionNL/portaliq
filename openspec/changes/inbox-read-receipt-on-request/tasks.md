# Tasks: inbox-read-receipt-on-request

- [ ] **T01**: Add `readReceiptRequested` (boolean, default false), `readAt` (date-time) and `sendingRef` (string) to `portalMessage` in `lib/Settings/portaliq_register.json`, each with a `title` and `description`; bump the schema version to 0.7.0 (REQ-IRR-001)
  - Run the register import on a dev instance and check `occ` logs for `PARTIAL IMPORT`
- [ ] **T02**: `InboxMessageFields` accepts `readReceiptRequested` as a `messageFields` key; `PortalInboxReader` copies `readReceiptRequested` and `readAt` onto the inbox row (REQ-IRR-001, REQ-IRR-003)
  - PHPUnit `PortalInboxReaderTest::testReceiptFieldsReachTheInboxRow`, `InboxMessageFieldsTest::testKeepsTheReceiptRequestKey`
- [ ] **T03**: `InboxMessageFields::readPayload()` takes the current row and returns the payload of design.md "Mark-read" rules 1 to 4; `ContributionController::markRead()` passes the row `writeScoped()` already read (REQ-IRR-002)
  - PHPUnit `InboxMessageFieldsTest::testNoRequestWritesNoMoment`, `::testFirstOpenWritesTheMoment`, `::testAppInboxKeepsAnExistingReadAt`
  - PHPUnit `ContributionControllerTest::testMarkReadKeepsTheFirstReadMoment`, `::testMarkReadNeverWritesTheReceiptRequest`
- [ ] **T04**: Berichten shows the notice line on a message with a receipt request, in `src/site/pages/inbox/InboxPage.vue` (or `MessagesPage.vue`, whichever renders the card); add the Dutch and English strings to `src/site/pages/inbox/strings.js` and `l10n/` (REQ-IRR-003)
  - node `tests/inbox-read-receipt.spec.mjs`: notice with a request, none without
- [ ] **T05**: `src/manifest.json` PortalMessageDetail: "Gelezen" shows `readAt` or "nog niet", a "Leesbevestiging" row when requested, and a link from "Ontvanger" to `PortalMessages?sendingRef=…` when set; the history line "Gelezen door {ontvanger}" (REQ-IRR-004, REQ-IRR-005)
- [ ] **T06**: `src/manifest.json` PortalMessages: columns "Ontvanger", "Gelezen", "Gelezen op"; a `sendingRef` filter; the count "{n} van {m} gelezen" above the table when filtered (REQ-IRR-005)
  - node `tests/inbox-read-receipt.spec.mjs` reads the manifest for T05 and T06
- [ ] **T07**: Live check on a dev instance: write three `portalMessage` rows with one `sendingRef` and a request, open one as the resident twice, and screenshot the message page and the filtered index for the build PR
- [ ] **T08**: Tell planninq and learniq in their issue trackers which fields to set when they send a class letter (`readReceiptRequested`, `sendingRef`)
