## ADDED Requirements

### Requirement: An administrator chooses how each column of a case type reads

On a portal's Case types page, each case type MUST open "Kolommen in Mijn zaken", listing every field the case apps show in Mijn zaken. Per field the administrator MUST be able to choose "Zoals de zaakapp het toont", Tekst, Link, QR-code or Datum, and for a link or QR code the words shown with it ("Tekst van de link", at most 60 characters). A QR code choice MUST show a preview with the code and its words under "Zo ziet de inwoner het". Saving MUST store the settings of that case type on the portal (`caseTypeColumns`) and MUST leave the other case types' settings as they were. Only an administrator MAY read or save them. A field name that is not a plain name, a render outside the four kinds, and words on a text or date column MUST NOT be stored.

#### Scenario: The permit proof as a wallet code
- GIVEN the portal Mijn Zuiddrecht and the case type Parkeervergunning binnenstad, whose case app shows the field Vergunningsbewijs
- WHEN the administrator chooses QR-code with "Toevoegen aan wallet" and saves
- THEN the portal stores `{field: "permitProof", render: "qr", linkLabel: "Toevoegen aan wallet"}` for that case type
- AND the preview shows a QR code with "Toevoegen aan wallet" under it
- AND the settings of the case type Afval stay as they were
- @e2e exclude admin save, covered by PHPUnit `PortalCaseTypesControllerTest::testSavingOneTypesColumnsKeepsTheOtherTypes` and `node --test tests/case-type-columns.spec.mjs`

#### Scenario: Back to the case app
- GIVEN the case type Parkeervergunning binnenstad with stored column settings
- WHEN the administrator sets every field to "Zoals de zaakapp het toont" and saves
- THEN the portal stores no settings for that case type
- @e2e exclude admin save, covered by PHPUnit `PortalCaseTypesControllerTest::testAnEmptyListRemovesTheType`

### Requirement: A case row reads with its case type's columns

For a `cases` collection, every case row the serving portal shows MUST carry the portal's settings for the row's case type, and the site MUST draw that row's columns, in the list and on the case page, with the stored render and words in place of the case app's. A row of a case type without settings, and a row of any other collection, MUST read as the case app declares.

#### Scenario: One list, two case types
- GIVEN the QR setting above, and Fatima with a parking permit case and a waste case in Mijn zaken
- WHEN she opens Mijn zaken
- THEN the parking permit row shows "Toevoegen aan wallet" with a "Toon QR-code" button
- AND the waste case row shows Vergunningsbewijs as the case app declares it
- @e2e exclude server stamp and render, covered by PHPUnit `ColumnOverridesTest` and `node --test tests/case-type-columns.spec.mjs` ("one table draws a QR toggle on the set row and a link on the other")
