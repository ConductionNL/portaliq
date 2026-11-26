## ADDED Requirements

### Requirement: A form opens and closes on set dates and after a set number of submissions (REQ-FGV-001)

A form binding SHALL accept `availability` with `activeFrom`, `activeUntil`, `limit` (`count` per `total`, `month` or `year`), `reopensOn`, `maintenance` and `replacedBy`. Outside its active period, during maintenance, or once the limit of the current period is reached, the route SHALL show the matching situation from the FormulierNietBeschikbaar board instead of the form, and the server MUST refuse a submission to it. A saved draft MUST stay when a form is closed.

#### Scenario: The 150 places are taken
- **WHEN** the front garden subsidy has `limit: {count: 150, per: "total"}`, `reopensOn: 2027-01-05` and 150 submissions
- **THEN** the route shows "Er kunnen nu geen aanvragen meer bij" and "Vanaf dinsdag 5 januari 2027 kunt u weer een aanvraag doen.", with the weekday worked out from the date

#### Scenario: Maintenance keeps the draft
- **WHEN** a resident with a saved Woo draft opens the form during the maintenance window of 9 October 2026, 18:00 to 22:00
- **THEN** the route shows "Dit formulier is even niet beschikbaar" with the window "vrijdag 9 oktober 2026 van 18:00 tot 22:00 uur" and says the saved answers stay

#### Scenario: A replaced form points to its successor
- **WHEN** the binding has `replacedBy: "/aanvragen/woo-verzoek-2027"`
- **THEN** the route shows "Dit formulier is niet meer beschikbaar" with a link to the new form

#### Scenario: The admin sees the counter
- **WHEN** an administrator opens the form's settings with 41 submissions this month and a monthly limit of 500
- **THEN** the status panel reads "Inzendingen deze maand 41 van maximaal 500"

### Requirement: Each form names where its submissions go (REQ-FGV-002)

Rewritten onto the direct submit (Ruben, 25 Nov 2026: form-governance T04-T08 rewritten onto the direct submit). A form binding names its `destination { register, schema }` (REQ-PIFO-007) and MAY name `destinationRules`, a list of `{ when, destination }`. `when` SHALL use the local `visibleWhen` grammar of the form's conditional questions. Before the submit, the first rule whose `when` holds over the answers SHALL decide the destination; with no match the binding's own `destination` SHALL apply. The submit then creates the object at that destination in the same request; there is no routing step after it. A rule without a condition, with a condition the portal cannot check, or without a register and schema SHALL never route and SHALL be a finding that stops the binding from being published, and every rule's destination SHALL be checked against its schema like the binding's own. A mailbox or an external system (ZGW, StUF, a JSON endpoint) is reached through a destination schema whose app forwards it (integriq), not by portaliq.

#### Scenario: An environment question goes to the environment register
- **WHEN** a Woo request answers `onderwerp: milieu` and the binding has a rule `{when: {field: onderwerp, op: eq, value: milieu}, destination: {register: milieu, schema: melding}}`
- **THEN** the submit creates a `milieu/melding` object and no dossiq case

#### Scenario: No rule holds
- **WHEN** the same form answers `onderwerp: verkeer`
- **THEN** the submit creates the object at the binding's own destination

#### Scenario: A broken rule stops publishing
- **WHEN** a binding has a rule without register or schema
- **THEN** the binding cannot be published and the finding names `destinationRules.<n>`

### Requirement: A failed submit is reported and can be sent again (REQ-FGV-003)

Rewritten onto the direct submit (Ruben, 25 Nov 2026: form-governance T04-T08 rewritten onto the direct submit). Nothing is queued and nothing is retried in the background: a submit either creates the destination object in the request or does not. When the destination does not answer (503), the server SHALL give the failure an error code `OF-` plus four characters, write one evidence line in the server log with that code, the form and the destination's status, and answer the code with the 503. The form SHALL keep the answers and show "Het versturen is niet gelukt", the error code and "Opnieuw proberen". Every send of one filled-in form SHALL carry the same Idempotency-Key, so a retry after an answer that got lost creates no second object. A refused answer (422) and a form that cannot be sent (403, 404) SHALL carry no error code. Once a day at 07:00 the portal's administrators SHALL get one mail listing the forms whose submits failed in the past 24 hours with their error codes, and no mail when there is nothing to list.

#### Scenario: The resident retries
- **WHEN** a submit fails because the destination does not answer, and the resident chooses "Opnieuw proberen" once the destination answers again
- **THEN** the page shows the reference of the created object, the server log has one line with the error code the resident saw, and both sends carried the same Idempotency-Key

#### Scenario: A refused answer is not a failure
- **WHEN** the destination refuses an answer with 422
- **THEN** the refusal comes back on its field and no error code is given or logged

#### Scenario: One digest a day
- **WHEN** two submits of the Woo form failed yesterday and nothing else failed
- **THEN** the administrators get one mail at 07:00 naming the Woo form and both error codes

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
