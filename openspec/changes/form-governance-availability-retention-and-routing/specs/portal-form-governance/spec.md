## ADDED Requirements

### Requirement: A form opens and closes on set dates and after a set number of submissions (REQ-FGV-001)

A form binding SHALL accept `availability` with `activeFrom`, `activeUntil`, `limit` (`count` per `total`, `month` or `year`), `reopensOn`, `maintenance` and `replacedBy`. Outside its active period, during maintenance, or once the limit of the current period is reached, the route SHALL show the matching situation from the FormulierNietBeschikbaar board instead of the form, and the server MUST refuse a submission to it. A saved draft MUST stay when a form is closed.

#### Scenario: The 150 places are taken
- **WHEN** the front garden subsidy has `limit: {count: 150, per: "total"}`, `reopensOn: 2027-01-05` and 150 submissions
- **THEN** the route shows "Er kunnen nu geen aanvragen meer bij" and "Vanaf maandag 5 januari 2027 kunt u weer een aanvraag doen."

#### Scenario: Maintenance keeps the draft
- **WHEN** a resident with a saved Woo draft opens the form during the maintenance window
- **THEN** the route shows "Dit formulier is even niet beschikbaar" with the window and says the saved answers stay

#### Scenario: A replaced form points to its successor
- **WHEN** the binding has `replacedBy: "/aanvragen/woo-verzoek-2027"`
- **THEN** the route shows "Dit formulier is niet meer beschikbaar" with a link to the new form

#### Scenario: The admin sees the counter
- **WHEN** an administrator opens the form's settings with 41 submissions this month and a monthly limit of 500
- **THEN** the status panel reads "Inzendingen deze maand 41 van maximaal 500"

### Requirement: Each form names where its submissions go (REQ-FGV-002)

A form binding SHALL accept `delivery` with a default target and rules that pick another target from the answers. The target kinds SHALL be `caseType`, `email` and `integriq`. The first rule whose condition holds SHALL decide the target; with no match the default SHALL apply. A binding without `delivery` SHALL deliver to its case type's app, as today.

#### Scenario: An environment question goes to the mailbox
- **WHEN** a Woo request answers `onderwerp: milieu` and the binding has a rule to `milieu@zuiddrecht.nl`
- **THEN** the submission is mailed to that address and no case is created in dossiq

#### Scenario: An external target through integriq
- **WHEN** the default target is `{kind: "integriq", source: "zgw-zaken"}`
- **THEN** portaliq hands the submission to that integriq connection and records the reference integriq returns

### Requirement: A failed registration is retried and reported (REQ-FGV-003)

A submission whose delivery failed SHALL be retried in the background after 5 minutes, 1 hour and 6 hours. The resident's processing page SHALL show "Het versturen is niet gelukt", the date the answers are kept until, "Opnieuw proberen" and an error code. Staff SHALL be able to retry from the submission's page. Each attempt SHALL be a line in the evidence log. Once a day the portal's administrators SHALL get one mail listing failed deliveries, failed confirmations, failed prefills and bindings that open no form, and no mail when there is nothing to list.

#### Scenario: The resident retries
- **WHEN** a delivery failed and the resident chooses "Opnieuw proberen" and the target answers
- **THEN** the page shows the reference of the created case and the evidence log has two attempts

#### Scenario: One digest a day
- **WHEN** two deliveries failed three times yesterday and nothing else failed
- **THEN** the administrators get one mail at 07:00 naming both submissions

#### Scenario: A quiet day
- **WHEN** nothing failed in the past 24 hours
- **THEN** no digest is sent

### Requirement: Submissions are removed after their retention period (REQ-FGV-004)

A form binding SHALL accept `retention` with `completedDays`, `incompleteDays`, `failedDays` and `method` (`delete` or `anonymise`), defaulting to 30, 30 and 90 days and `delete`. Each submission and draft SHALL carry the expiry its state gives it. After expiry the portal SHALL delete it through OpenRegister, or with `anonymise` SHALL remove the answers and keep the reference, binding, dates and state.

#### Scenario: Anonymised after 30 days
- **WHEN** a completed Woo submission is 31 days old and the binding says `method: anonymise`
- **THEN** its answers are gone and its reference and dates remain

#### Scenario: A failed submission stays longer
- **WHEN** a failed submission is 31 days old and `failedDays` is 90
- **THEN** it is still there with its answers

### Requirement: Staff who manage a form can export its submissions (REQ-FGV-005)

A user who may manage a form binding SHALL be able to download its submissions for a chosen period as CSV or XLSX, one column per visible field plus reference, submitted at and state. Every export MUST write an audit line with the user, the binding, the period and the row count. A user who may not manage the binding MUST NOT get the action or the file. The list download on PtInzendingen SHALL NOT contain answers.

#### Scenario: September's Woo requests
- **WHEN** the form manager downloads the Woo requests of September as XLSX
- **THEN** the file has one row per submission and the audit log records the export

#### Scenario: Not a manager of this form
- **WHEN** an editor without rights on the binding calls the export route
- **THEN** the portal refuses it
