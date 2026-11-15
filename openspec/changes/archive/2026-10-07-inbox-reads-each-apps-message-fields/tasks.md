# Tasks: inbox-reads-each-apps-message-fields

- [x] **T01**: `InboxMessageFields::normalise()` keeps a well-formed `messageFields` on a `kind: inbox` collection and drops it otherwise; wired into `CollectionConfigNormaliser` (REQ-IMF-001)
  - PHPUnit `InboxMessageFieldsTest::testKeepsPlainFieldNamesOnAnInbox`, `::testDropsItOnANonInboxCollection`, `::testDropsAMalformedEntry`
- [x] **T02**: `PortalInboxReader` maps each declared field onto the inbox row before it sorts and counts (REQ-IMF-001)
  - PHPUnit `PortalInboxReaderTest::testDeclaredMessageFieldsFillTheInboxRow`, `::testReadAtMarksARowRead`, `::testMappedRowsSortByTheirOwnDate`
- [x] **T03**: `ContributionController::markRead()` writes the current time into `readAt` when the collection names it, else `read: true` (REQ-IMF-002)
  - PHPUnit `ContributionControllerTest::testMarkReadWritesTheDeclaredReadAtField`
- [x] **T04**: `PortalInboxReader` adds `_files` to rows of an inbox collection that declares `filesDownload` (inbox-reply-with-attachments REQ-IRA-004)
  - PHPUnit `PortalInboxReaderTest::testFilesOnlyWhenDeclared`
- [x] **T05**: The site inbox lists a message's files and downloads one through `portalApi.downloadFile()` (inbox-reply-with-attachments REQ-IRA-004)
  - node `tests/inbox-attachments.spec.mjs`
