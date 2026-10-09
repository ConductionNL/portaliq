# Tasks: inbox-reply-with-attachments

## The declaration

- [x] **T01**: Accept `reply: {action, carry, subjectFrom}` on a `kind: inbox` collection; drop it when the action is not a create action of the same contribution; keep only carried fields the action whitelists and the collection projects (REQ-IRA-001)
  - PHPUnit `InboxReplyConfigNormaliserTest::testKeepsAWellFormedReply`, `::testDropsAForeignAction`, `::testDropsACarryOutsideTheWhitelist`
## The reply

- [x] **T02**: Extract the create pipeline from `ContributionController::create()` into a service, with `create()` calling it unchanged (REQ-IRA-002)
  - PHPUnit: the existing `ContributionController` create tests pass unchanged Extracted into the private `createFrom()` of `ContributionController`, which `create()` and `reply()` both call, rather than into a separate service class; the existing create tests pass unchanged.
- [x] **T03**: `contribution#reply` on `POST /portal/api/inbox/{register}/{schema}/{id}/reply`: prove the message, resolve the action, carry fields on the server, write through the pipeline (REQ-IRA-002)
  - PHPUnit `InboxReplyTest::testReplyCarriesTheCaseFromTheMessage`, `::testClientCaseIdIsOverwritten`, `::testForeignMessageIs404`, `::testCollectionWithoutReplyIs404`, `::testLowTrustIs403` Tested by `InboxReplyTest`.
## Attachments

- [x] **T04**: `PortalInboxReader` adds `_files` to rows of inbox collections that declare `filesDownload` (REQ-IRA-004)
  - PHPUnit `PortalInboxReaderTest::testFilesOnlyWhenDeclared` Already built before this change was picked up (`withFiles()` in `PortalInboxReader`, `PortalInboxReaderTest::testFilesOnlyWhenDeclared`).
## The screen

- [x] **T05**: "Reply" under a message with a reply declaration, in the site's `src/site/pages/InboxPage.vue`; the in-place form with subject, text and the action's file fields; send, then upload through `fileFieldSubmit.js` (REQ-IRA-002, REQ-IRA-003)
  - Playwright `tests/e2e/inbox-reply-with-attachments.spec.ts`: a resident replies to a handler's message with a PDF, and the reply object carries the case id of the original message and the file Built in `InboxReply.vue`, `reply.js` and `InboxPage.vue`, tested by `tests/inbox-reply.spec.mjs`; the form sits in the site inbox page. — not run: the Playwright spec needs a live instance.
- [x] **T06**: The partial-failure message when an upload fails after the reply was written (REQ-IRA-003)
  - Playwright `tests/e2e/inbox-reply-with-attachments.spec.ts`: a stubbed upload failure shows the partial-failure message and the reply exists Tested in `tests/inbox-reply.spec.mjs`. — not run: the Playwright spec with a stubbed upload failure.
- [x] **T07**: Incoming attachments listed under a message in `src/site/pages/InboxPage.vue` and downloadable (REQ-IRA-004)
  - Playwright `tests/e2e/inbox-reply-with-attachments.spec.ts`: a resident downloads a file a handler attached Already built before this change was picked up (`tests/inbox-attachments.spec.mjs`). — not run: the Playwright spec.
## Docs and strings

- [x] **T08**: Dutch and English strings for the button, the form, the confirmation and the partial failure; the contract docs page gains `reply` and explains why a reply goes to the case app
- [ ] **T09**: `openspec validate inbox-reply-with-attachments --strict` — not run: the openspec CLI is not installed here.