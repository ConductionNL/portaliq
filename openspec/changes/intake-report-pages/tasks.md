# Tasks: intake-report-pages

## Before any screen

- [ ] **T01**: On a live instance, as a signed-in Nextcloud user outside every report group, read `portalReporterContact` through OpenRegister's objects API. If it returns rows, narrow the schema's `authorization` in `lib/Settings/portaliq_register.json` and bump the register version (design D7). Verification: the live call and its result in the PR body; `ReportControllerTest` stays green.

## Access

- [ ] **T02**: Add optional `handlerGroup` to `portalReportDeclaration`; gate `show()`, `reply()` and `requestReveal()` on handler or custodian membership, with the single 404 (REQ-IRP-005). Verification: `ReportControllerTest::testNonHandlerGets404`, `::testCustodianHandlesWhenNoHandlerGroup`.
- [ ] **T03**: `ReportController::index()` and route `GET /api/reports`: projected reports and terms for the caller's case types (REQ-IRP-004). Verification: `ReportControllerTest::testIndexNeverReturnsContact`, `::testIndexListsOnlyHandledCaseTypes`.
- [ ] **T04**: `ReportController::revealRequests()` and route `GET /api/reveal-requests`: pending requests for the caller's custodian groups (REQ-IRP-006). Verification: `ReportControllerTest::testRevealRequestsOnlyForCustodian`.

## The reporter's pages

- [ ] **T05**: `src/site/components/ReportBlock.vue` as widget `report` in `PUBLIC_WIDGETS`: challenge, fields, optional contact block, file, show the code once (REQ-IRP-001). Verification: Vitest on the component; `tests/e2e/intake-report-pages.spec.ts` files a report in a real browser.
- [ ] **T06**: `src/site/components/ReportThreadBlock.vue` as widget `reportThread`: code entry, thread, terms, answer, uniform wrong-code message (REQ-IRP-002). Verification: the same Playwright spec returns with the code and answers.
- [ ] **T07**: The page designer warning for a report widget on a page outside `traffic.excludedPaths` (REQ-IRP-003). Verification: the Playwright spec opens the designer and sees the warning.

## The staff screens

- [ ] **T08**: `WrongdoingReportList` and `WrongdoingReportDetail` custom pages and the menu entry in `src/manifest.json`: list, detail, thread, reply with the visibility toggle, reveal request with motivation (REQ-IRP-004). Verification: the Playwright spec replies as a handler and the reporter reads it.
- [ ] **T09**: `RevealRequestDesk` custom page: pending requests, allow or refuse with a reason, contact shown once (REQ-IRP-006). Verification: the Playwright spec allows a reveal and then checks the report detail shows no contact.

## Docs, strings and validation

- [ ] **T10**: English and Dutch strings for both widgets and the three staff pages ("Send report", "Keep this code. We cannot send it to you again.", "We could not open a report with this code.", "Reports of wrongdoing", "Reveal requests", "Visible to the reporter", "Allow", "Refuse"); a docs page for the organisation on setting up the reporting channel, including `handlerGroup`, `custodianGroup` and `traffic.excludedPaths`. Verification: `npm run lint`, `test:l10n`.
- [ ] **T11**: `openspec validate intake-report-pages --strict`.
