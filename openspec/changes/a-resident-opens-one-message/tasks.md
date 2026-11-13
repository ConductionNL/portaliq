# Tasks: a-resident-opens-one-message

- [x] **T01**: Route `GET /portal/api/inbox/{register}/{schema}/{id}` (`contribution#message`) in `appinfo/routes.php`, before the `/portal/{path}` catch-all (REQ-ROM-002)
  - Gate route-reachability and route-auth on the diff
- [x] **T02**: `ContributionController::message()`: session, `authorisedInboxCollection()`, `minTrust`, scope value, then the scoped read, in that order; 401 / 403 / 404 as design.md "Endpoint" (REQ-ROM-002)
  - PHPUnit `ContributionControllerTest::testMessageAnswers401WithoutSession`, `::testMessageOfAnotherResidentIs404`, `::testMessageOutsideOwnContributionsIs404`, `::testMessageBelowMinTrustIs403`, `::testMessageReadsNothingBeforeTheChecks`
- [x] **T03**: `PortalInboxReader`: extract `projectRow()` from `collectionRows()` and add `oneMessage()` that projects one verified row the same way (REQ-ROM-002, REQ-ROM-005)
  - PHPUnit `PortalInboxReaderTest::testOneMessageCarriesSourceFilesAndReply`, `::testListAndOneMessageProjectAlike`
- [x] **T04**: `src/site/pages/inbox/MessagePage.vue`: header, read badge, case card, "Over dit bericht", text, "Bijlagen", the two back links and the bottom case button, per design.md "Screens" (REQ-ROM-001, REQ-ROM-004, REQ-ROM-005)
  - node `tests/site-message-page.spec.mjs`: rows left out without a value, no card without a record link, no "Bijlagen" without `filesDownload`
- [x] **T05**: Site routing: `/mijn/<inbox page>/<source>/<id>` opens `MessagePage`; the source resolves to register and schema from the contributions payload; an unknown source shows the not-found state (REQ-ROM-001, REQ-ROM-002)
  - node test for the route parser in `inbox.js`
- [x] **T06**: `InboxPage.vue` and `InboxBlock.vue`: each message title links to its page (REQ-ROM-001)
- [x] **T07**: Mark read on open through `api.markMessageRead()`, once, then emit the new unread count (REQ-ROM-003)
  - node test: an unread message calls mark-read once; a read one never does
- [x] **T08**: Reply form and action button on the page when declared, reusing `InboxReply` and `actionOf()` (REQ-ROM-006)
- [x] **T09**: Strings in `src/site/pages/inbox/strings.js` and `l10n/` (en, nl): "Terug naar berichten", "Ontvangen op {date} om {time}", "Over dit bericht", "Bijlagen", "Naar uw zaak", "Gelezen", "Dit bericht bestaat niet of is niet voor u."
- [ ] **T10**: Playwright on the example resident: Sanne opens the Woo decision from Berichten, sees "Gelezen" and both attachments, follows "Naar uw zaak"; a foreign id shows the not-found text (REQ-ROM-001 to REQ-ROM-005)
- [ ] **T11**: Add the BerichtDetail row to the PT measurement set once the page is live, and pixel-compare it with the board
