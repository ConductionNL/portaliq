# Tasks: cases-documents-on-the-case

## The contract

- [x] **T01**: Accept `documents: {label, provider}` on a collection, validated like `timeline.provider`; drop a malformed key (REQ-CDC-001)
  - PHPUnit `DocumentsProviderMethodTest::testKeepsAWellFormedDeclaration`, `::testDropsAContractMethodName`, `::testDropsANonIdentifier`
- [x] **T02**: A reader that calls the declared method with the case id and keeps only well-formed entries (REQ-CDC-001)
  - PHPUnit `PortalCaseDocumentReaderTest::testKeepsWellFormedEntries`, `::testDropsAnEntryWithoutFile`, `::testAFailingProviderGivesNoEntries`

## The case screen

- [x] **T03**: `CitizenCaseController::show()` returns the provider's entries without their `file` reference, plus the resident's tagged uploads, and no untagged folder file (REQ-CDC-001, REQ-CDC-004)
  - PHPUnit `CitizenCaseControllerTest::testShowNeverReturnsAFileReference`, `::testShowListsTaggedUploadsOnly`, `::testShowWithoutProviderListsOnlyUploads`
- [x] **T04**: `PortalFileWriter::attachFile()` tags a citizen upload `portal:from-applicant` (REQ-CDC-004)
  - PHPUnit `PortalFileWriterTest::testCitizenUploadIsTagged`

## The download

- [x] **T05**: `citizenCase#document` on `GET /portal/api/citizen/cases/{register}/{schema}/{id}/documents/{documentId}`: prove the case, look the id up in the provider's answer, stream, audit (REQ-CDC-002)
  - PHPUnit `CitizenCaseControllerTest::testStreamsAPublishedDocument`, `::testForeignCaseIs404`, `::testUnlistedIdIs404`, `::testDownloadIsAudited`
- [x] **T06**: `upload:<fileId>` streams only a tagged file from the case folder (REQ-CDC-004)
  - PHPUnit `CitizenCaseControllerTest::testUntaggedFolderFileIs404`

## The screen

- [x] **T07**: `CitizenCase.jsx`: decision first under "Decision", then "Documents", then "Sent by you", each entry a download through `portalApi.downloadCitizenDocument()` (REQ-CDC-002, REQ-CDC-003)
  - Playwright `tests/e2e/cases-documents-on-the-case.spec.ts`: a resident downloads the decision letter from their case
- [x] **T08**: The empty state and the failed-download message (REQ-CDC-005)
  - Playwright `tests/e2e/cases-documents-on-the-case.spec.ts`: a case without published documents shows the empty state

## Docs and strings

- [x] **T09**: Dutch and English strings for the headings, the empty state and the failure message; the contract docs page gains the `documents` key and the entry shape a case app returns
- [x] **T10**: `openspec validate cases-documents-on-the-case --strict`

## Notes from the build (2026-09-29)

- D5 changed: since portaliq#817 (merged 2026-09-28) the case screen listed the files the organisation released in OpenRegister where the collection opted into `filesDownload`. A case app without a `documents` method keeps that list (entry ids `released:<fileId>`), instead of dropping to uploads only; with a method, the method alone decides.
- The listing and the download live in `lib/Service/CitizenCaseDocuments.php`; `CitizenCaseController` only calls it. A foreign case answers 404 on the download route, where the other citizen routes answer 403, so a document id gives no existence oracle.
- The download tests are in `CitizenCaseControllerTest`, not a separate `CitizenCaseDocumentTest`. The screen is pinned by `tests/case-documents-screen.spec.mjs`; the Playwright file is written, not run in the build session.
