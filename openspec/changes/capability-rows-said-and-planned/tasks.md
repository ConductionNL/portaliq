# Tasks: capability-rows-said-and-planned

- [x] **T01**: Write the requirements of this change and record them on their rows in `openspec/parity/capabilities.json`

## Check what is built

- [ ] **T02**: Check REQ-RSP-001 to REQ-RSP-007 and REQ-RSP-009 to REQ-RSP-016 against the code the rows cite, tag the tests that cover them with `@e2e` or `@e2e exclude`, and move a row that the code does not meet to `specified` with a "Not built" evidence line

## Build what is owed

- [x] **T03**: Sort search results by publication date, newest first, with the order in the page address (REQ-RSP-008, row `sib-opencatalogi-srch-sort`), in the search block of `src/site`, with a node test
- [ ] **T04**: In dossiq, declare the `ask-partner` action on the case contribution and offer the handler's ask button on the case, calling `POST /api/partner-tasks/ask` (REQ-PTP-002, rows `tsk-ask-partner` and `sib-dossiq-3-19`); then retire dossiq's `ExternalConsultationResponse` page
- [ ] **T05**: In dossiq, declare a `via` over the case roles on `mijnZaken`, so a co-applicant's own subject reaches the shared case (row `dem-tnd-joint-case-partner`); live check with two DigiD dev sessions
- [ ] **T06**: In filinq (ConductionNL/filinq#1256), give the signer collection's row actions their consent and reason fields and make `viewDocument` a row action, so portaliq's signing dialog reaches the receiver (REQ-SGN-004, row `dem-tnd-sign-in-portal`)
- [ ] **T07**: On the resident's case page in `src/site`, offer "Ask a question about this case" from dossiq's create action and list the case's messages from its `caseMessages` provider, newest first (dossiq REQ-PORTAL-007, row `cmp-act-ask-question-case`), with a node test
