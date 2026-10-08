## ADDED Requirements

### Requirement: A cases collection may name the field its status reads as

A `cases` collection MAY declare `statusLabelField`. The normaliser MUST keep it only as a non-empty string naming one of the projected `fields`, or any field when the collection projects none. The case list MUST stamp the field's text on each own and mandated row as `_statusLabel` when it holds text, and MUST leave the raw status on the row. "My cases" MUST show `_statusLabel` when present and the status as before otherwise.

#### Scenario: A Woo request reads "Ontvangen", not a uuid
- GIVEN dossiq's `mijnZaken` declares `statusLabelField: "statusPublicLabel"`
- AND a case has `status: "3c0f5a00-…-b001"` and `statusPublicLabel: "Ontvangen"`
- WHEN the resident opens "Mijn zaken"
- THEN the row shows "Ontvangen"
- AND the row still carries the raw `status`
- @e2e exclude pinned by `PortalCaseListReaderTest::testEachRowCarriesTheStatusWordsTheCollectionNames` and `tests/my-cases-page.spec.mjs`

#### Scenario: A label field the rows never carry is dropped
- GIVEN a `cases` collection with `fields: ["status"]` and `statusLabelField: "statusPublicLabel"`
- WHEN the manifest is normalised
- THEN the collection has no `statusLabelField`
- @e2e exclude pinned by `PortalManifestNormaliserTest::testAStatusLabelFieldIsKeptOnlyWhenItNamesAProjectedField`
