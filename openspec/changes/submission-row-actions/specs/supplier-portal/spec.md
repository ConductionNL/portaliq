# supplier-portal Delta: submission-row-actions

## ADDED Requirements

### Requirement: An employee can register a failed receipt again

A `portalSubmission` row whose `deliveryStatus` is `failed` or `pending` SHALL offer "Register again" in its row menu on the Submissions list. Pressing it SHALL write the ontvangstbevestiging (`portalMessage`) for the record's own `subjectRef`, `organisation` and `payloadCopy`, set the record's `receiptMessageRef` to the new message and its `deliveryStatus` to `delivered`. The record's `submittedAt`, `payloadCopy`, `appId` and `actionId` SHALL NOT change. A write that fails SHALL leave `deliveryStatus` `failed` and tell the employee so. A `delivered` row SHALL NOT offer the action, and the endpoint SHALL refuse it with 409. Only a member of `portaliq-medewerkers` MAY call the endpoint; the check SHALL run before the record is read, and a record the caller cannot read SHALL answer 404.

#### Scenario: A failed receipt is registered again
@e2e exclude the receipt write is server-side; covered by SubmissionReceiptServiceTest::testRegisterAgainWritesTheReceiptAndMarksTheRowDelivered and SubmissionAdminControllerTest
- **GIVEN** the submission "Grof afval laten ophalen" with `deliveryStatus` `failed` and no `receiptMessageRef`
- **WHEN** Pieter opens its row menu and chooses "Opnieuw registreren"
- **THEN** the resident's inbox holds the ontvangstbevestiging for that submission
- **AND** the row reads "Bevestigd" and its `submittedAt` is unchanged

#### Scenario: A delivered row cannot be registered twice
@e2e exclude covered by SubmissionAdminControllerTest::testADeliveredRowIsRefused and the manifest visibility test
- **GIVEN** a submission with `deliveryStatus` `delivered`
- **WHEN** Pieter opens its row menu
- **THEN** the menu has no "Opnieuw registreren"
- **AND** a direct call to the endpoint answers 409 and writes nothing

#### Scenario: A retry that fails leaves the row failed
@e2e exclude covered by SubmissionReceiptServiceTest::testAFailedRetryKeepsTheRowFailed
- **GIVEN** a failed submission whose receipt write throws again
- **WHEN** Pieter chooses "Opnieuw registreren"
- **THEN** the row still reads "Mislukt" and Pieter reads that the receipt could not be registered again

#### Scenario: Someone outside the staff group is refused first
@e2e exclude covered by SubmissionAdminControllerTest::testANonStaffCallerIsRefusedBeforeAnyRead
- **GIVEN** a signed-in user who is not in `portaliq-medewerkers`
- **WHEN** they call the register-again endpoint for any id
- **THEN** the answer is 403 and no record is read or written

### Requirement: An employee can start a Talk conversation about a submission

While Talk (`spreed`) is installed, every row of the Submissions list SHALL offer "Conversation in Talk". It SHALL start a Talk room linked to the submission through OpenRegister's Talk integration, named after the submitted action and its receipt reference, and open it. A submission that already has a linked room SHALL open that room instead of starting another. Without Talk the entry SHALL NOT show.

#### Scenario: A conversation starts from the row
@e2e exclude needs Talk on the test instance; covered by tests/submission-row-actions.spec.mjs ("a conversation starts from the row, linked to the submission, and opens")
- **GIVEN** Talk is installed and the submission "Grof afval laten ophalen" has no linked room
- **WHEN** Pieter chooses "Gesprek in Talk"
- **THEN** a room named "Grof afval laten ophalen" with the submission's reference opens
- **AND** the submission lists that room under its Talk links

#### Scenario: The second time opens the same room
@e2e exclude covered by tests/submission-row-actions.spec.mjs ("the second time opens the linked room and makes no second one")
- **GIVEN** the submission already has a linked room
- **WHEN** Pieter chooses "Gesprek in Talk" again
- **THEN** that room opens and no second room is made

### Requirement: An employee can link a submission to the request form it came through

Every row of the Submissions list SHALL offer "Link a form". It SHALL open a dialog listing the request forms (`portalFormBinding`) of the submission's portal; choosing one SHALL store its id on the submission as `formBinding`. The action SHALL write no other field. A `portalSubmission` update that changes any proof field (`subjectRef`, `organisation`, `appId`, `actionId`, `actionTitle`, `payloadCopy`, `submittedAt`) SHALL be refused.

#### Scenario: A form is linked
@e2e exclude covered by tests/submission-row-actions.spec.mjs ("linking a form writes formBinding only and shows the list again") and the write-guard unit test
- **GIVEN** the submission "Grof afval laten ophalen" without `formBinding` and the request form "Grof afval aanvragen" on its portal
- **WHEN** Pieter chooses "Formulier koppelen" and picks "Grof afval aanvragen"
- **THEN** the submission's `formBinding` is that form's id
- **AND** its proof fields are unchanged

#### Scenario: A proof field cannot be edited
@e2e exclude covered by SubmissionWriteGuardListenerTest::testAProofFieldChangeIsRefused
- **GIVEN** any submission
- **WHEN** someone saves it with a different `payloadCopy`
- **THEN** the save is refused and the record is unchanged

### Requirement: The submission row menu reads as the board

The row menu of the Submissions list SHALL list, in this order: Edit, Link a form, Conversation in Talk (while Talk is installed) and Register again (on a failed or pending row), and nothing else. A submission is a proof of receipt: the list SHALL NOT offer Copy or Delete, neither in a row menu nor as an action on a selection. A submission SHALL leave the log only through the retention and archival rules.

#### Scenario: The menu on a failed row
@e2e exclude covered by tests/submission-row-actions.spec.mjs (manifest order)
- **GIVEN** Talk is installed and a failed submission
- **WHEN** Pieter opens its row menu
- **THEN** it reads Bewerken, Formulier koppelen, Gesprek in Talk, Opnieuw registreren

#### Scenario: A submission cannot be copied or deleted from the list
@e2e exclude covered by tests/submission-row-actions.spec.mjs (showCopyAction, showDeleteAction, showMassCopy and showMassDelete are false)
- **GIVEN** any submission on the Submissions list
- **WHEN** Pieter opens its row menu, or selects rows and opens the list's Acties menu
- **THEN** neither menu offers Kopiëren, Verwijderen, Selectie kopiëren or Selectie verwijderen
