## ADDED Requirements

### Requirement: A listed document's download conditions are read and accepted through the app's hooks

A collection's `documents` declaration MAY name a `terms` hook and an `accept` hook, each a plain method name on the contributing app's provider. Portaliq MUST call them only for a document the app lists on an object proven to be the subject's own, with the session's subject (never one taken from the request). `terms` answers whether the document may be opened now and the conditions in force with their version; portaliq MUST pass on only `mayDownload`, `message` and `agreement`. `accept` records the subject's acceptance of the version shown; anything but exactly true MUST be treated as not recorded. The `opened` hook still decides every download.

#### Scenario: A released decision with download conditions
- **GIVEN** filinq lists a released decision whose download agreement version v2 the resident has not accepted
- **WHEN** the resident presses Downloaden
- **THEN** the conditions of version v2 are shown with "Akkoord en downloaden", and the file downloads only after the acceptance is recorded
- @e2e exclude component behaviour covered by `node --test tests/mijn-documents.spec.mjs`; the hooks by PHPUnit `CollectionDocumentsTest` and `PortalCollectionDocumentsControllerTest`

#### Scenario: The conditions changed meanwhile
- **GIVEN** the resident was shown version v1 and filinq's agreement moved on to v2
- **WHEN** the resident accepts
- **THEN** the acceptance is refused with a sentence and nothing downloads
- @e2e exclude asserted in PHPUnit `PortalCollectionDocumentsControllerTest::testAcceptingAnswersWhetherItWasRecorded` and the node test
