## ADDED Requirements

### Requirement: A documents block may show the documents of every record it can read

A `documents` block MAY declare `eachRecord: true`. Such a block MUST stay on any page of its contribution, not only on its collection's record page, and MUST keep `titleFields` and `subtitleFields` only as lists of fields the collection projects. Any other value of `eachRecord` MUST leave the record-page rule as it is. A documents block MAY declare `subtitle` and `emptyLabel`, kept trimmed when they are text of at most 400 characters. Listing and opening a document of one row MUST go through the per-object route, which proves the row is the subject's before the provider is asked.

#### Scenario: Fatima's two children on one page
- GIVEN learniq's page `parentDocuments` with a block `{type: documents, collection: parentChildren, eachRecord: true, titleFields: [givenName], subtitleFields: [groupLabel]}` and no record collection
- WHEN the manifest is normalised
- THEN the block stays, with `eachRecord: true` and both field lists
- AND a block with `eachRecord: "yes"` on the same page is dropped
- @e2e exclude normaliser output, covered by PHPUnit `PortalBlockResolverTest::testADocumentsBlockForEachRecordStaysOnAnyPage` and `BoardKeysTest::testADocumentsBlockKeepsItsSubtitleAndEmptyWords`

### Requirement: A documents entry may carry its own line, a new mark and a status

The entry reader MUST keep an entry's `meta`, `status` and `statusTone` when each is a non-empty text of at most 200 characters, and `isNew` when it is a boolean. Any other key or value MUST be left out. The `file` reference MUST still never reach the browser.

#### Scenario: A new report
- GIVEN learniq answers a report with `isNew: true` and `meta: "Groep 6"`
- WHEN the guardian's page lists it
- THEN the row shows "Nieuw" and "Groep 6"
- @e2e exclude reader output, covered by PHPUnit `PortalCaseDocumentReaderTest::testKeepsTheLineTheNewMarkAndTheStatus`
