# Tasks: inbox-delete-own-messages

- [x] **T1**: `PortalObjectWriter::deleteObject()` deletes a row only when it is the subject's alone (scope field, tenant, no shared list)
  - PHPUnit `PortalObjectWriterDeleteTest` (deletes the subject's own row with RBAC bypassed; never another subject's, another tenant's, a shared or an unknown row)
- [x] **T2**: `ContributionController::deleteMessage()` and its route; own notices always, an app's inbox only with `deletable: true`; trust re-checked; `CollectionConfigNormaliser` keeps `deletable` as a strict boolean
  - PHPUnit `ContributionControllerTest::testDeleteMessageRemovesTheResidentsOwnNotice`, `::testDeleteMessageOfAnotherResidentIs404`, `::testDeleteMessageFromAnAppsInboxNeedsItsConsent`
- [x] **T3**: `PortalInboxReader` marks a row `_source.deletable`
  - PHPUnit `PortalInboxReaderTest::testEachRowSaysWhetherTheResidentMayDeleteIt`
- [x] **T4**: the Berichten page deletes one or several messages after a confirmation on the page
  - `node --test tests/inbox-delete.spec.mjs` (`npm run check:inbox-delete`)
