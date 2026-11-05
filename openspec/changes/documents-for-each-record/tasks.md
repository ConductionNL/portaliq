# Tasks: documents-for-each-record

- [x] **T1** `ListBlockNormaliser` keeps `eachRecord: true` with projected `titleFields` and `subtitleFields` on a documents block, and `recordPageOnly` keeps such a block on any page; `BoardKeys` keeps `subtitle` and `emptyLabel`. Tests: `PortalBlockResolverTest::testADocumentsBlockForEachRecordStaysOnAnyPage`, `BoardKeysTest::testADocumentsBlockKeepsItsSubtitleAndEmptyWords`.
- [x] **T2** `DocumentEntryExtras`: the entry reader keeps `meta`, `isNew`, `status`, `statusTone`. Test: `PortalCaseDocumentReaderTest::testKeepsTheLineTheNewMarkAndTheStatus`.
- [x] **T3** Site: the page loads an each-record block's collection and draws one `DocumentsBlock` per row, with the row's heading and subtitle, its own heading id and the block's empty words; a paper that is not a case's reads its day, type and size. Tests: `node --test tests/site-documents-groups.spec.mjs`.
- [ ] **T4** learniq declares the guardian's "Rapporten en documenten" page with two each-record blocks (`guardian-reports-and-documents`). Tracked in learniq.
