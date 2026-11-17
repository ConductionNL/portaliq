# Tasks: capability-rows-said-and-planned

- [x] **T01**: Write the requirements of this change and record them on their rows in `openspec/parity/capabilities.json`

## Check what is built

- [x] **T02**: Check REQ-RSP-001 to REQ-RSP-007 and REQ-RSP-009 to REQ-RSP-016 against the code the rows cite, tag the tests that cover them with `@e2e` or `@e2e exclude`, and move a row that the code does not meet to `specified` with a "Not built" evidence line
  - Checked 2026-11-15. Every scenario carries an `@e2e` or `@e2e exclude` line naming the test that covers it. Two requirements are not met in full, so their rows move to `specified` with a "Not built" line:
    REQ-RSP-013 (`ops-audit-trail`, `cmp-ops-audit`): only session revocation writes an audit row; withdrawing an account, moving it to another audience and an emergency message do not.
    REQ-RSP-010 (`ana-per-portal-config`): the collector refuses events of a portal that measures nothing, but `templates/site.php` still loads the traffic client, which then stays idle.
    Scenarios with no browser test yet: REQ-RSP-002 (changing the name) and REQ-RSP-007 (the hero search).

## Build what is owed

- [x] **T03**: Sort search results by publication date, newest first, with the order in the page address (REQ-RSP-008, row `sib-opencatalogi-srch-sort`), in the search block of `src/site`, with a node test
- [x] **T04**: In dossiq, declare the `ask-partner` action on the case contribution and offer the handler's ask button on the case, calling `POST /api/partner-tasks/ask` (REQ-PTP-002, rows `tsk-ask-partner` and `sib-dossiq-3-19`); then retire dossiq's `ExternalConsultationResponse` page
  - Built in dossiq (ConductionNL/dossiq#6554): the case page header action `ask-partner` opens `PartnerAskDialog`, which posts the case, the partner's KvK number and e-mail address, a due date and an optional required PDF to `POST /api/partner-tasks/ask`. `ExternalConsultationResponsePage` was already retired (dossiq `ConsultationRoutesTest`). The live ask from a case to a partner's inbox is not checked yet.
  - T05 left: dossiq's `role` rows carry `participant`, not the co-applicant's portal subject, and a `via` on `mijnZaken` would replace its `portalSubject` scoping, so the applicant's own cases would need a role row too. That needs the role rows written with the subject first, and the live check with two DigiD sessions.
- [ ] **T05**: In dossiq, declare a `via` over the case roles on `mijnZaken`, so a co-applicant's own subject reaches the shared case (row `dem-tnd-joint-case-partner`); live check with two DigiD dev sessions
- [x] **T06**: In filinq (ConductionNL/filinq#1256), give the signer collection's row actions their consent and reason fields and make `viewDocument` a row action, so portaliq's signing dialog reaches the receiver (REQ-SGN-004, row `dem-tnd-sign-in-portal`)
  - Built in filinq (ConductionNL/filinq#2134): `sign` declares `fields: ["consent"]`, `decline` `fields: ["reason"]`, and `viewDocument` is an endpoint row action (`rowField: signingRequestId`, `scopeClaim: signerEmail`) named in `signerSigningRequests`' row actions, pinned by `PortalContributionProviderTest::testEachSigningActForwardsOnlyWhatItsReceiverReads` and the signer manifest golden. A live signature from the portal is not checked yet.
- [x] **T07**: On the resident's case page in `src/site`, offer "Ask a question about this case" from dossiq's create action and list the case's messages from its `caseMessages` provider, newest first (dossiq REQ-PORTAL-007, row `cmp-act-ask-question-case`), with a node test
  - Built in portaliq: a collection's `messages: {label, provider}` (`lib/Contribution/CaseMessagesProviderMethod.php`), `GET /portal/api/collections/{register}/{schema}/{id}/messages` on `PortalTimelineController::messages()` (ownership proven first, newest first, `lib/Contribution/CaseMessageEntries.php`), and a `messages` record block with `askAction` / `askLabel` (`src/site/components/mijn/MessagesBlock.vue`, the ask button a `withRecord` cta). Node test `tests/case-messages.spec.mjs`. Dossiq's half: put `{type: 'messages', collection: 'mijnZaken', askAction: 'askAboutCase'}` on its resident case page.
