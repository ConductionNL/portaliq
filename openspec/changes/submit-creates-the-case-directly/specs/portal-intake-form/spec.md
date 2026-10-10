# portal-intake-form Delta: submit-creates-the-case-directly

**Status**: draft
**Scope**: portaliq intake and embed submit, form bindings. Implements hydra `form-submits-into-its-destination-object`.

## ADDED Requirements

### Requirement: A form binding names its destination and is valid against it (REQ-PIFO-007)

A `portalFormBinding` SHALL store `destination { register, schema }`. Saving or publishing a binding SHALL run OpenRegister's form destination validator over the bound form. A binding with findings SHALL NOT be published once the validator is in refuse mode.

#### Scenario: A binding to a case type missing a required field is refused
- **GIVEN** a form that does not ask for `communicationChannel`, bound to a case type requiring it before creation
- **WHEN** an administrator publishes the binding
- **THEN** the publish is refused and the preview names `communicationChannel` as `required-unmapped`

## RENAMED Requirements

- FROM: `### Requirement: A submission is validated against the form's schema before any create (REQ-PIFO-004)`
- TO: `### Requirement: A submission is validated against its destination's schema before anything is stored (REQ-PIFO-004)`

- FROM: `### Requirement: The case is created asynchronously and the citizen gets a reference at once (REQ-PIFO-005)`
- TO: `### Requirement: The case is created in the request and the citizen gets its reference at once (REQ-PIFO-005)`

- FROM: `### Requirement: A Woo request form is delivered to opencatalogi's intake`
- TO: `### Requirement: A Woo request form submits into the dossiq Woo case`

## MODIFIED Requirements

### Requirement: A submission is validated against its destination's schema before anything is stored (REQ-PIFO-004)

The portal SHALL send a submission to its destination through OpenRegister's submit service, which validates it against the destination schema. A refusal SHALL come back as an error per field. Nothing SHALL be stored for a refused submission.

#### Scenario: A missing required answer is a field error
- **GIVEN** a form whose destination requires a postcode
- **WHEN** a citizen submits without one
- **THEN** the postcode field carries the error and no object exists
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: Refusal happens before the case app is called
- **GIVEN** a submission that fails the portal's own checks (honeypot, challenge, statements, rate limit)
- **WHEN** it is posted
- **THEN** no submit reaches the destination and nothing is stored
- @e2e exclude Ordering at the seam; covered by PHPUnit

#### Scenario: A value only the destination refuses comes back on its field
- **GIVEN** a destination property with an enum the form's own check does not know
- **WHEN** a citizen submits a value outside it
- **THEN** the response is 422 and that field shows the destination's message

### Requirement: The case is created in the request and the citizen gets its reference at once (REQ-PIFO-005)

A valid submission SHALL create the case in the same request. The confirmation SHALL name the case reference, the received moment and, where the destination returns them, the term start and the deadline. The page behind that reference SHALL read the case.

#### Scenario: The confirmation names the case
- **GIVEN** a valid submission into a dossiq case type
- **WHEN** it is posted
- **THEN** the confirmation shows the case number, the received moment, the term start and the deadline from the response
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: The citizen is not held on the page
- **GIVEN** a valid submission
- **WHEN** it is posted
- **THEN** the confirmation appears with the response that created the case, without a later poll
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: A failed create is visible, not silent
- **GIVEN** a submission the destination refuses
- **WHEN** it is posted
- **THEN** the form shows why on each refused field and keeps the answers
- **AND** nothing is stored, so there is no failed entry for an administrator to chase

#### Scenario: A destination that is down refuses, it does not queue
- **GIVEN** the destination app is unavailable
- **WHEN** a citizen submits
- **THEN** the page says the request was not sent and to try again later, and keeps the answers in the form
- **AND** nothing is stored in portaliq

### Requirement: A Woo request form submits into the dossiq Woo case

A submission from a Woo request form SHALL create the dossiq Woo case in the same request. The confirmation SHALL quote the case number, the received moment, the term start and the due date.

#### Scenario: the term is armed
- GIVEN a published Woo request form and dossiq installed
- WHEN a citizen submits it
- THEN the response carries the dossiq case number and its due date
- AND the confirmation names both

#### Scenario: the term did not start
- GIVEN dossiq cannot arm the statutory term for the new Woo case
- WHEN a citizen submits
- THEN the case is not created, the response is a refusal with the reason, and the form asks the citizen to try again or get in touch

#### Scenario: opencatalogi is not installed
- GIVEN opencatalogi is not installed and dossiq is
- WHEN a citizen submits a Woo request form
- THEN the dossiq Woo case is created as usual, because opencatalogi is no longer on this path

#### Scenario: dossiq is not installed
- GIVEN dossiq is not installed
- WHEN an administrator publishes a Woo request form
- THEN the publish is refused because the destination does not resolve
