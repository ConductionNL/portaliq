# Tasks: cases-my-cases-page

## Contract

- [ ] **T01**: `closedField` in `CollectionConfigNormaliser`, kept only when it names a projected field; `_closed` on each row in `PortalCaseListReader` (REQ-CMC-002)
  - Verify: PHPUnit `PortalManifestNormaliserTest` for a valid, an unprojected and a missing `closedField`; `PortalCaseListReaderTest` for `_closed`
- [ ] **T02**: The contributions answer announces whether any `kind: cases` collection exists (REQ-CMC-001)
  - Verify: PHPUnit on the contributions controller

## Screen

- [ ] **T03**: `api.fetchMyCases(mandateId)` and `MyCasesPage.jsx` with the merged list, source label per row, the mandate label on mandated rows, and the empty state (REQ-CMC-001, REQ-CMC-003)
  - Verify: Playwright `tests/e2e/cases-my-cases-page.spec.ts` with two contributing apps' cases in one list
- [ ] **T04**: "Open" and "Closed" tabs with counts; "Closed" hidden when no collection declares `closedField` (REQ-CMC-002)
  - Verify: the same Playwright spec with one closed case
- [ ] **T05**: The "Acting for" switcher in the header, kept in session storage and sent as `mandate` on the list and the case screen (REQ-CMC-004)
  - Verify: Playwright: two employees of one company each see both cases; a colleague without a mandate sees neither (the scenario of `portal-identity-and-the-organisations-cases` T14, now in a browser)
- [ ] **T06**: `409 group_too_large` shown with its sentence (REQ-CMC-004)
  - Verify: PHPUnit fixture over the bound; Playwright asserts the message
- [ ] **T07**: A row opens the case on its contribution's page (REQ-CMC-005)
  - Verify: Playwright opens a case from the list and sees its case screen

## Close

- [ ] **T08**: Dutch and English strings; docs for contributing apps on `kind: cases` and `closedField`; hand the dossiq half to the dossiq lane; `openspec validate cases-my-cases-page --strict`
