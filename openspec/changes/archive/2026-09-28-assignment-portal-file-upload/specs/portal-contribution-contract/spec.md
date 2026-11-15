---
status: proposed
---

# Spec: portal-contribution-contract (file fields on create and update actions)

## ADDED Requirements

### Requirement: An action MUST be able to declare a file field

A `type: create` or `type: update` action SHALL be able to mark a whitelisted
field as a file field with `fieldConfigs.<field>.type: file`, plus optional
`multiple` (boolean), `accept` (extensions such as `.pdf` or MIME types such as
`image/*`) and `maxSizeMb` (1 to 50). The normaliser SHALL keep `type` only when
its value is `file` and the action is a create or update action, SHALL coerce
`multiple` to a strict boolean, SHALL drop every malformed `accept` entry and
keep at most 20, and SHALL clamp `maxSizeMb` into 1 to 50. A file config on a
field outside the whitelist SHALL be dropped with the rest of that config, as
every field config already is.

#### Scenario: A sound file field survives normalisation
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testASoundFileFieldIsKept}

- **GIVEN** a create action whitelisting `attachmentRefs` with `fieldConfigs.attachmentRefs = {type: file, multiple: true, accept: [".PDF", "image/*"], maxSizeMb: 20}`
- **WHEN** the manifest is normalised
- **THEN** the config SHALL keep `type: file`, `multiple: true`, `accept: [".pdf", "image/*"]` and `maxSizeMb: 20`

#### Scenario: A malformed file config fails closed
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testMalformedFileKeysAreDroppedOrClamped}

- **GIVEN** a field config `{type: "file", accept: ["pdf", "<script>", 7], maxSizeMb: 900}` and another `{type: "upload"}`
- **WHEN** the manifest is normalised
- **THEN** the first SHALL keep `type: file` with no `accept` and `maxSizeMb: 50`
- **AND** the second SHALL carry no `type` at all

#### Scenario: A file field on an endpoint action is not a file field
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/ActionConfigNormaliserFileFieldTest.php::testAFileTypeOnAnEndpointActionIsDropped}

- **GIVEN** a `type: endpoint` action with a field config `{type: file}`
- **WHEN** the manifest is normalised
- **THEN** that config SHALL carry no `type`

### Requirement: A file field MUST never be written from a request body

On every create path (authenticated and anonymous) and on update, Portaliq
SHALL remove every declared file field from the whitelisted request body before
the write. Only the scoped field upload SHALL write a file field, with a
reference Portaliq produced itself.

#### Scenario: A typed reference is removed before the write
@e2e exclude {the attack is a hand-crafted body the portal form never sends; asserted in tests/Unit/Controller/ContributionControllerFileFieldTest.php::testCreateDropsATypedFileFieldValue and ::testUpdateDropsATypedFileFieldValue}

- **GIVEN** a create action whose `attachmentRefs` is a file field
- **WHEN** the client posts `{assignmentId: "a1", attachmentRefs: ["/admin/files/secret.pdf"]}`
- **THEN** the object SHALL be written with `assignmentId` only

### Requirement: A subject MUST be able to upload into a declared file field of an object they own

`POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}?action=<id>`
(multipart part `file`) SHALL attach one file to the object and write its
reference into the field. The request SHALL name the action; it SHALL be one of
the subject's own create or update actions for this register and schema, and
the field SHALL be a declared file field of it, else 403 before any read.
The action's `minTrust` SHALL be re-checked (403). Ownership SHALL be proven
the way the action writes it: for a create action the stored scope field SHALL
equal the subject reference that create stamps, for an update action the value
SHALL resolve through the action's `scopeClaim`. A foreign or absent object
SHALL be one 404 with nothing attached. For a create action the object SHALL
have been created within the last 30 minutes (403 `upload_window_closed`).
The file SHALL be checked against `accept` (415) and `maxSizeMb` (413, default
20) before it is attached. After the attach, the Nextcloud file id SHALL be
written as a string: appended when the field is `multiple` and the schema
property is an array, else as the only value. A field already holding 20
references SHALL refuse with 409.

#### Scenario: A pupil attaches work to the submission they just created
@e2e exclude {the attach reaches OpenRegister's FileService, which fails on a fresh CI instance (portaliq#29, the same reason tests/e2e/portal-document-download.spec.ts is grep-inverted); asserted in tests/Unit/Controller/PortalFieldFileControllerTest.php::testUploadAttachesAndAppendsTheReference}

- **GIVEN** a create action `createSubmission` with file field `attachmentRefs` (`multiple: true`) and a submission the subject created a minute ago holding `["4702"]`
- **WHEN** the subject uploads `essay.pdf` naming `action=createSubmission`
- **THEN** the file SHALL be attached to that submission through `PortalFileWriter`
- **AND** `attachmentRefs` SHALL be `["4702", "<new file id>"]`

#### Scenario: Every refusal happens before any attach
@e2e exclude {fail-closed ordering observable only at the seam (the writer is never called); asserted in tests/Unit/Controller/PortalFieldFileControllerTest.php::testForeignObjectIs404BeforeAnyAttach, ::testUndeclaredFieldIs403BeforeAnyRead and ::testCreateWindowClosedRefusesBeforeAnyAttach}

- **GIVEN** a field that is not a declared file field, a foreign object id, and an object the create action made an hour ago
- **WHEN** an upload is attempted against each
- **THEN** the answers SHALL be 403, 404 and 403 `upload_window_closed`
- **AND** `PortalFileWriter::attachFile()` SHALL never be reached

#### Scenario: Type and size are checked before the attach
@e2e exclude {needs crafted file bodies; asserted in tests/Unit/Service/PortalFileFieldPolicyTest.php::testAcceptMatchesExtensionOrMime and ::testSizeAboveTheLimitIsRefused}

- **GIVEN** a file field with `accept: [".pdf"]` and `maxSizeMb: 1`
- **WHEN** the subject uploads `run.exe`, then a 2 MB `essay.pdf`
- **THEN** the answers SHALL be 415 and 413, with nothing attached

### Requirement: The generic portal form MUST render a file field as a file picker

`SchemaForm` SHALL render a declared file field as a labelled file input
(honouring `multiple` and `accept`), SHALL leave it out of the create body,
SHALL refuse a picked file above `maxSizeMb` before anything is saved, and after
a successful create SHALL upload the picked files one at a time to the field
upload endpoint. When a file does not attach, the form SHALL keep the created
record and SHALL name the files that did not attach.

#### Scenario: The form renders a picker, not a text box
@e2e exclude {rendered with react-dom/server in tests/schema-form-file-field.spec.mjs::renders a file input for a file field; a live portal run needs a working attach (portaliq#29)}

- **GIVEN** an action whose `attachmentRefs` is a file field with `multiple: true` and `accept: [".pdf"]`
- **WHEN** the form renders
- **THEN** `attachmentRefs` SHALL be an `<input type="file" multiple accept=".pdf">` with its label

#### Scenario: Create first, then upload each file
@e2e exclude {the submit flow is driven against a fake api in tests/schema-form-file-field.spec.mjs::creates then uploads each file and names a failed one}

- **GIVEN** two picked files and a create that succeeds
- **WHEN** the second upload fails
- **THEN** the create body SHALL not contain the file field
- **AND** both uploads SHALL target the created object's id
- **AND** the form SHALL name the second file as not attached
