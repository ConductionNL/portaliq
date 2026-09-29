# citizen-case-documents Specification

## Purpose
A resident sees the documents the organisation published to them on their case, and only those, and opens each one from the case screen. The decision letter comes first. Closes portaliq matrix rows `cas-documents-view`, `cmp-cas-decision-download` and `cmp-cas-documents-published`.

## Requirements

### Requirement: The case app declares which documents a resident may see (REQ-CDC-001)

A case collection MAY declare `documents: {label, provider}`, where `provider` names a public method on the contributing app's own portal provider. Portaliq SHALL accept the declaration only when `provider` is a plain identifier that is not one of the contract's own methods. Portaliq SHALL call the method with the case id only after proving the case is the resident's. It SHALL keep only entries carrying an `id`, a `title` and a `file` reference, and SHALL NOT send the `file` reference to the browser.

#### Scenario: A resident sees what the organisation published
- **GIVEN** a case app whose documents method returns a decision letter and an information letter for a case
- **WHEN** the resident opens that case in the portal
- **THEN** the case screen lists both, and no other file from the case folder
- @e2e exclude needs a case app with a documents method; pinned by CitizenCaseControllerTest::testShowListsTaggedUploadsOnly and ::testShowNeverReturnsAFileReference

#### Scenario: A file reference never reaches the browser
- **GIVEN** a published document stored in an OpenRegister object folder
- **WHEN** the portal SPA calls `GET /portal/api/citizen/cases/{register}/{schema}/{id}`
- **THEN** the answer names the document's id, title, kind and date, and no register, schema or file id
- @e2e exclude a negative over the response; pinned by CitizenCaseControllerTest::testShowNeverReturnsAFileReference

### Requirement: Every listed document opens from the case screen (REQ-CDC-002)

`GET /portal/api/citizen/cases/{register}/{schema}/{id}/documents/{documentId}` SHALL prove the case is the resident's, SHALL look `documentId` up in the documents method's answer for that case, and SHALL stream that entry's file. A foreign case and an id the method did not return SHALL both get the same 404. Every download SHALL be audited.

#### Scenario: A resident downloads a published document
- **GIVEN** a resident whose case lists an information letter
- **WHEN** they press it on the case screen
- **THEN** the file downloads and the portal audit trail records a download
- @e2e exclude needs a case app with a documents method; pinned by CitizenCaseControllerTest::testStreamsAPublishedDocument and ::testDownloadIsAudited

#### Scenario: Another resident's document stays closed
- **GIVEN** a document published on someone else's case
- **WHEN** a resident requests it through their own session
- **THEN** the portal answers 404, as for a document that does not exist
- @e2e exclude pinned by CitizenCaseControllerTest::testForeignCaseIs404

#### Scenario: A guessed id is refused
- **GIVEN** a resident's own case
- **WHEN** they request a document id the case app did not return for that case
- **THEN** the portal answers 404
- @e2e exclude pinned by CitizenCaseControllerTest::testUnlistedIdIs404

### Requirement: The decision is shown first (REQ-CDC-003)

Entries the case app marks with `kind` `decision` SHALL be listed first on the case screen under "Decision", newest first, each with its date. Other published documents SHALL follow under "Documents" in the order the case app returned them.

#### Scenario: The decision letter is at the top
- **GIVEN** a decided case with a decision letter and three other documents
- **WHEN** the resident opens the case
- **THEN** the decision letter is first, under "Decision", with the decision date
- @e2e exclude needs a case app with a documents method; pinned by CitizenCaseControllerTest::testShowNeverReturnsAFileReference (order) and tests/case-documents-screen.spec.mjs (groups)

### Requirement: The resident's own uploads stay visible, and nothing else from the folder (REQ-CDC-004)

A document the resident adds through the portal SHALL be tagged `portal:from-applicant` when it is written. The case screen SHALL list tagged files under "Sent by you" and SHALL let the resident download them. A file in the case folder without that tag SHALL NOT be listed and SHALL NOT be downloadable through the case screen.

#### Scenario: The resident finds their own upload
- **GIVEN** a resident who added a photo to their case through the portal
- **WHEN** they open the case again
- **THEN** the photo is listed under "Sent by you" and opens

#### Scenario: An internal file stays internal
- **GIVEN** a handler's working note stored in the case object's folder without the tag
- **WHEN** the resident opens the case
- **THEN** the note is not listed, and a request for it answers 404
- @e2e exclude placing a staff file in the case folder needs OpenRegister's file API as staff; pinned by CitizenCaseControllerTest::testUntaggedFolderFileIs404 and PortalFileReaderTest::testTheTaggedListingKeepsOnlyTaggedFiles

### Requirement: A case with nothing published says so (REQ-CDC-005)

When the case app declares no documents method, or its method returns nothing, and the resident uploaded nothing, the case screen SHALL show "There are no documents on this case yet." A download that fails SHALL show "The document could not be opened. Try again later."

#### Scenario: An empty case
- **GIVEN** a case with no published documents and no uploads
- **WHEN** the resident opens it
- **THEN** the documents section shows "There are no documents on this case yet."
