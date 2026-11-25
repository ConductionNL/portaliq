# supplier-portal Delta: submission-records-its-action-title

## ADDED Requirements

### Requirement: A proof-log record names the submitted action as the resident read it

The `portalSubmission` record written after a successful submission SHALL carry `actionTitle`, the name of the submitted action as the resident read it when submitting: for a contributed create action, the `label` of the action declaration that authorised the submission; for a completed task, the task's title as shown in "Mijn taken" (`displayTitle`, else `title`). The title SHALL be stored as text in the language it was read in, never as a translation key. A title that is not a string or is blank after trimming SHALL NOT be written; a longer one SHALL be cut at 200 characters. The minimal fallback record SHALL carry the same title. Records written before this change SHALL NOT be rewritten; the Submissions list shows their action id.

#### Scenario: A create action's label is recorded
@e2e exclude the proof log is written server-side; covered by SubmissionReceiptServiceTest::testTheProofLogNamesTheActionByItsTitle and ContributionControllerTest::testSuccessfulCreatePassesTheActionsLabelAsItsTitle
- **GIVEN** dossiq contributes a create action `{id: "meldingOpenbareRuimte", label: "Melding openbare ruimte"}` on a Dutch portal
- **WHEN** Sanne submits it
- **THEN** the `portalSubmission` record has `actionId` "meldingOpenbareRuimte" and `actionTitle` "Melding openbare ruimte"
- **AND** the Submissions list reads "Melding openbare ruimte"

#### Scenario: An endpoint action's label is recorded
@e2e exclude covered by ContributionControllerTest::testActionWithTypeCreateAndSuccessfulForwardTriggersReceiptRecord
- **GIVEN** a `type: create` endpoint action with `label` "Grof afval laten ophalen"
- **WHEN** the domain app answers 2xx to the forward
- **THEN** the proof-log record carries `actionTitle` "Grof afval laten ophalen"

#### Scenario: A completed task is recorded by the task's title
@e2e exclude covered by PortalTaskProxyControllerTest::testACompletionIsRecordedByTheTasksTitle
- **GIVEN** an open task whose "Mijn taken" title is "Stuur uw bewijsstuk"
- **WHEN** the resident completes it
- **THEN** the proof-log record has `actionId` "task.complete" and `actionTitle` "Stuur uw bewijsstuk"

#### Scenario: An action without a label leaves the title out
@e2e exclude covered by SubmissionReceiptServiceTest::testABlankTitleIsNotWritten
- **GIVEN** a create action declared without a `label`, or with a blank one
- **WHEN** it is submitted
- **THEN** the proof-log record has no `actionTitle` key and the list shows the action id

#### Scenario: The fallback record keeps the title
@e2e exclude covered by SubmissionReceiptServiceTest::testTheFallbackRecordKeepsTheTitle
- **GIVEN** a submission whose full proof-log write fails
- **WHEN** the minimal fallback record is written
- **THEN** it carries `deliveryStatus` "failed" and the same `actionTitle`

#### Scenario: Earlier submissions are not rewritten
@e2e exclude no write happens; the list's fallback is pinned by tests/submission-action-title.spec.mjs
- **GIVEN** a `portalSubmission` record written before this change, without `actionTitle`
- **WHEN** the app is updated
- **THEN** the record is unchanged and the Submissions list shows its action id
