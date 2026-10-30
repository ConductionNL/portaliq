# Tasks: intake-report-pages

## Before any screen

- [ ] **T01**: On a live instance, as a signed-in Nextcloud user outside every report group, read `portalReporterContact` through OpenRegister's objects API. If it returns rows, narrow the schema's `authorization` in `lib/Settings/portaliq_register.json` and bump the register version (design D7). Verification: the live call and its result in the PR body; `ReportControllerTest` stays green.

## Access

- [x] **T02**: Add optional `handlerGroup` to `portalReportDeclaration`; gate `show()`, `reply()` and `requestReveal()` on handler or custodian membership, with the single 404 (REQ-IRP-005). Verification: `ReportControllerTest::testNonHandlerGets404`, `::testCustodianHandlesWhenNoHandlerGroup`.
  - Built (portaliq#819): `RevealService::mayHandle()`, `handlerGroup` on the declaration in `lib/Settings/portaliq_register.json`; pinned by `ReportControllerTest::testAColleagueOutsideTheGroupsCannotReadAnswerOrAskAboutAReport`, `::testAMemberOfTheHandlerGroupReadsTheReport`, `::testTheCustodianHandlesWhenNoHandlerGroupIsDeclared`.
- [x] **T03**: `ReportController::index()` and route `GET /api/reports`: projected reports and terms for the caller's case types (REQ-IRP-004). Verification: `ReportControllerTest::testIndexNeverReturnsContact`, `::testIndexListsOnlyHandledCaseTypes`.
- [x] **T04**: `ReportController::revealRequests()` and route `GET /api/reveal-requests`: pending requests for the caller's custodian groups (REQ-IRP-006). Verification: `ReportControllerTest::testRevealRequestsOnlyForCustodian`.

## The reporter's pages

- [x] **T05**: `src/site/components/ReportBlock.vue` as widget `report` in `PUBLIC_WIDGETS`: challenge, fields, optional contact block, file, show the code once (REQ-IRP-001). Verification: Vitest on the component; `tests/e2e/intake-report-pages.spec.ts` files a report in a real browser.
  - Built: `ReportBlock.vue` per MeldenZonderAccount (what happened, when and where, the optional contact block, the code shown once with copy, "Al gemeld?" linking to the follow-up page, what to expect, the no-trace line), `src/site/lib/reportApi.js` (challenge on surface `report`, honeypot inside the report, no cookie sent), widget `report` in `PUBLIC_WIDGETS`, the palette and its label; node `tests/report-pages.spec.mjs` (`check:report-pages`). The board's file field is left out: design.md says the form takes no attachments. "Al gemeld?" is a link, not a code field, so the code never travels in an address. Not run: the Playwright spec, which is the live pass (decision 139).
- [x] **T06**: `src/site/components/ReportThreadBlock.vue` as widget `reportThread`: code entry, thread, terms, answer, uniform wrong-code message (REQ-IRP-002). Verification: the same Playwright spec returns with the code and answers.
  - Built: `ReportThreadBlock.vue` per MeldingGesprek (code entry, the report, the conversation with "U" for the reporter, the answer, the terms as "Bevestiging binnen 7 dagen" with met or days left and the due date), widget `reportThread`; the same node spec. Not run: the Playwright spec (live pass).
- [ ] **T07**: The page designer warning for a report widget on a page outside `traffic.excludedPaths` (REQ-IRP-003). Verification: the Playwright spec opens the designer and sees the warning.

## The staff screens

- [ ] **T08**: `WrongdoingReportList` and `WrongdoingReportDetail` custom pages and the menu entry in `src/manifest.json`: list, detail, thread, reply with the visibility toggle, reveal request with motivation (REQ-IRP-004). Verification: the Playwright spec replies as a handler and the reporter reads it. (partly: the list is the index page AccountlessReports over `entitySource: endpoint`, manifest config instead of custom pages; the detail with thread, reply and reveal request is not built, because a detail page cannot yet read its record from an app endpoint in nextcloud-vue)
- [x] **T09**: `RevealRequestDesk` custom page: pending requests, allow or refuse with a reason, contact shown once (REQ-IRP-006). Verification: the Playwright spec allows a reveal and then checks the report detail shows no contact. (the RevealRequests index page over `entitySource: endpoint` with the row actions Allow and Refuse, RevealDecisionDialog and RevealedContactDialog; manifest config instead of a custom page)

## Docs, strings and validation

- [ ] **T10**: English and Dutch strings for both widgets and the three staff pages ("Send report", "Keep this code. We cannot send it to you again.", "We could not open a report with this code.", "Reports of wrongdoing", "Reveal requests", "Visible to the reporter", "Allow", "Refuse"); a docs page for the organisation on setting up the reporting channel, including `handlerGroup`, `custodianGroup` and `traffic.excludedPaths`. Verification: `npm run lint`, `test:l10n`.
  - Partly: the widgets' Dutch and English words are in `src/site/lib/reportApi.js` (same keys, no em-dash, tested). Open: the docs page, and the words of the staff detail page that T08 still has to build.
- [ ] **T11**: `openspec validate intake-report-pages --strict`.
