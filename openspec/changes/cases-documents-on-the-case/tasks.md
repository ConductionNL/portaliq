# Tasks: cases-documents-on-the-case

## The contract

- [ ] **T01**: Accept `documents: {label, provider}` on a collection, validated like `timeline.provider`; drop a malformed key (REQ-CDC-001)
  - PHPUnit `DocumentsProviderMethodTest::testKeepsAWellFormedDeclaration`, `::testDropsAContractMethodName`, `::testDropsANonIdentifier`
- [ ] **T02**: A reader that calls the declared method with the case id and keeps only well-formed entries (REQ-CDC-001)
  - PHPUnit `PortalCaseDocumentReaderTest::testKeepsWellFormedEntries`, `::testDropsAnEntryWithoutFile`, `::testAFailingProviderGivesNoEntries`

## The case screen

- [ ] **T03**: `CitizenCaseController::show()` returns the provider's entries without their `file` reference, plus the resident's tagged uploads, and no untagged folder file (REQ-CDC-001, REQ-CDC-004)
  - PHPUnit `CitizenCaseControllerTest::testShowNeverReturnsAFileReference`, `::testShowListsTaggedUploadsOnly`, `::testShowWithoutProviderListsOnlyUploads`
- [ ] **T04**: `PortalFileWriter::attachFile()` tags a citizen upload `portal:from-applicant` (REQ-CDC-004)
  - PHPUnit `PortalFileWriterTest::testCitizenUploadIsTagged`

## The download

- [ ] **T05**: `citizenCase#document` on `GET /portal/api/citizen/cases/{register}/{schema}/{id}/documents/{documentId}`: prove the case, look the id up in the provider's answer, stream, audit (REQ-CDC-002)
  - PHPUnit `CitizenCaseDocumentTest::testStreamsAPublishedDocument`, `::testForeignCaseIs404`, `::testUnlistedIdIs404`, `::testDownloadIsAudited`
- [ ] **T06**: `upload:<fileId>` streams only a tagged file from the case folder (REQ-CDC-004)
  - PHPUnit `CitizenCaseDocumentTest::testUntaggedFolderFileIs404`

## The screen

- [ ] **T07**: `CitizenCase.jsx`: decision first under "Decision", then "Documents", then "Sent by you", each entry a download through `portalApi.downloadCitizenDocument()` (REQ-CDC-002, REQ-CDC-003)
  - Playwright `tests/e2e/cases-documents-on-the-case.spec.ts`: a resident downloads the decision letter from their case
- [ ] **T08**: The empty state and the failed-download message (REQ-CDC-005)
  - Playwright `tests/e2e/cases-documents-on-the-case.spec.ts`: a case without published documents shows the empty state

## Docs and strings

- [ ] **T09**: Dutch and English strings for the headings, the empty state and the failure message; the contract docs page gains the `documents` key and the entry shape a case app returns
- [ ] **T10**: `openspec validate cases-documents-on-the-case --strict`
