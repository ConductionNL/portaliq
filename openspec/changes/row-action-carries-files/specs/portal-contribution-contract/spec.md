## ADDED Requirements

### Requirement: An endpoint row action may carry the files the resident adds (REQ-RAF-001)

An endpoint row action MAY declare `files: {field, max?, maxBytes?}`. The portal SHALL keep the declaration only on an endpoint row action whose `field` is a plain field name that is neither one of the action's `fields` nor its `rowField`, with `max` a positive integer of at most 10 (default 5) and `maxBytes` a positive integer of at most 26214400 (default 10485760); any other declaration SHALL be dropped. The confirmation dialog SHALL then offer a file input and SHALL refuse, before sending, more than `max` files or a file over `maxBytes`. The row-scoped forward SHALL read the uploads under `field` only for an action that keeps the declaration, SHALL answer 422 with `too_many_files` or `file_too_large` before the audit and the forward, and SHALL forward the action's whitelisted fields and the files as one multipart request, each file as a part named `<field>[]` with its own name and type, through the same assertion-signed, instance-local forward. Without files the forward SHALL stay the JSON body it was.

#### Scenario: A requester answers a question with a scan
<!-- @e2e exclude The upload goes through dossiq's live portal route; pinned by PortalRowActionControllerTest::testDeclaredFilesAreForwardedWithTheBody and PortalActionForwarderTest::testFilesGoMultipartBesideTheFields, and the dialog by tests/case-actions-row-inputs.spec.mjs. Live check: task 3.1. -->

- **GIVEN** the row action `beantwoordVraag` with `files: {field: attachments, max: 5}`
- **WHEN** a requester writes an answer, adds `scan.pdf` under "Bestanden toevoegen" and continues
- **THEN** dossiq receives the answer field and `scan.pdf` as the part `attachments[]` in one request

#### Scenario: Too many files are refused before anything is forwarded
<!-- @e2e exclude An API-level refusal the dialog already prevents; pinned by PortalRowActionControllerTest::testTooManyFilesIs422AndNotForwarded and the dialog test. -->

- **GIVEN** an action with `files: {field: attachments, max: 1}`
- **WHEN** two files are posted to the row-scoped forward
- **THEN** the answer is 422 `too_many_files`, nothing is audited and nothing is forwarded

#### Scenario: An action without files stays as it was
<!-- @e2e exclude Unchanged behaviour; pinned by PortalActionForwarderTest::testWithoutFilesTheForwardStaysJson and RowActionFilesTest::testAnActionWithoutFilesReadsNoUploads. -->

- **GIVEN** an endpoint row action without `files`
- **WHEN** a request with an upload is posted to it
- **THEN** the upload is not read and the forward is the JSON body of its fields
