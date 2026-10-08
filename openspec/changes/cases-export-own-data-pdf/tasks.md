# Tasks: cases-export-own-data-pdf

## The contract

- [x] **T01**: Add `exportPdf` to the boolean collection flags in `CollectionConfigNormaliser`, and report it on the contributions payload only when OpenRegister's rows renderer is present (REQ-OPX-001)
  - PHPUnit `CollectionConfigNormaliserTest::testExportPdfIsBoolean`, `ContributionControllerTest::testExportPdfHiddenWithoutRenderer`
## The export

- [x] **T02**: `exportCollectionPdf()` on `GET /portal/api/collections/{register}/{schema}/export.pdf`, same authorisation and scoped read as `collection()` (REQ-OPX-001, REQ-OPX-002)
  - PHPUnit `ContributionExportPdfTest::testListExportUsesTheScopedRead`, `::testCollectionWithoutOptInIs404`, `::testTrustBelowMinTrustIs403`
- [x] **T03**: `exportObjectPdf()` on `GET /portal/api/collections/{register}/{schema}/{id}/export.pdf`, same authorisation as `object()` (REQ-OPX-002)
  - PHPUnit `ContributionExportPdfTest::testForeignObjectIs404`
- [x] **T04**: Columns, labels and title from the collection; render through OpenRegister's rows method; 503 when absent; 400 on `ExportTooLargeException` (REQ-OPX-003, REQ-OPX-004)
  - PHPUnit `ContributionExportPdfTest::testOnlyProjectedFieldsReachTheRenderer`, `::testMissingRendererIs503`, `::testTooLargeIs400`
  - OpenRegister's `renderRowsToPdf` is the sibling half and does not exist yet; the route answers 503 until it does. The fake in the test names the contract. — not run: needs openregister
- [x] **T05**: Audit each export with the `download` verb (REQ-OPX-005)
  - PHPUnit `ContributionExportPdfTest::testExportIsAudited`
## The screen

- [x] **T06**: "Download as PDF" above an opted-in collection block and in `DetailCard`, in the site's `src/site/components/` (`CollectionTable.vue`, `DetailCard.vue`); `downloadPdf()` on the shared `src/shared/portalApi.js` (REQ-OPX-001)
  - Playwright `tests/e2e/cases-export-own-data-pdf.spec.ts`: a resident downloads their statement list as a PDF, and the file starts with `%PDF`
  - Built in `PdfDownloadButton.vue` (above the list in `ContributionPage.vue`, in `DetailCard.vue`) and `downloadPdf()` in `src/shared/portalApi.js`, tested by `tests/pdf-download.spec.mjs`. — not run: the Playwright spec needs a live instance.
- [x] **T07**: The too-large and failure messages (REQ-OPX-004)
  - Playwright `tests/e2e/cases-export-own-data-pdf.spec.ts`: a stubbed 400 shows the too-large message
  - Unit-tested through the button's message handling. — not run: the Playwright spec with a stubbed 400.
## Docs and strings

- [x] **T08**: Dutch and English strings for the button and the two messages; the contract docs page gains `exportPdf`
- [ ] **T09**: `openspec validate cases-export-own-data-pdf --strict` — not run: the openspec CLI is not installed here.