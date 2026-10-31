# portal-intake-form Delta: submit-creates-the-case-directly

**Status**: draft
**Scope**: portaliq intake and embed submit, form bindings. Implements hydra `form-submits-into-its-destination-object`.

## ADDED Requirements

### Requirement: A form binding names its destination and is valid against it (REQ-PIFO-007)

A `portalFormBinding` SHALL store `destination { register, schema }`, and MAY name `subjectField` (the property that holds the signed-in resident's subject reference), `paymentField` (the property the payment state is read from), `fields` (a form field written into a differently named property) and `fixed` (values every submit writes). Saving or publishing a binding SHALL run OpenRegister's form destination validator over the bound form. A binding with findings SHALL NOT be published, from the first release (decision 181); a binding in `draft` MAY be saved with its findings, which the preview shows (decision 189). When OpenRegister asks which published forms write into a schema that is about to change (`FormDestinationDependentsEvent`), portaliq SHALL announce every published binding, of every portal, whose destination is that schema and that renders a form here, and SHALL set a binding back to `draft` when OpenRegister has it unpublished (decision 181).

#### Scenario: A draft binding saves with its findings
- **GIVEN** a binding in status `draft` whose form misses a required property of its destination
- **WHEN** an administrator saves it
- **THEN** it is saved, and the preview names the finding

#### Scenario: A binding to a case type missing a required field is refused
- **GIVEN** a form that does not ask for `communicationChannel`, bound to a case type requiring it before creation
- **WHEN** an administrator publishes the binding
- **THEN** the publish is refused and the preview names `communicationChannel` as `required-unmapped`

#### Scenario: The destination schema changes under a published binding
- **GIVEN** a published binding whose form writes `toelichting` into its destination schema
- **WHEN** OpenRegister is about to save that schema without `toelichting` and asks which published forms write into it
- **THEN** portaliq announces the binding as a form of app `portaliq`, with the mapping a submit of it sends and audience `public`
- **AND** when OpenRegister has the form unpublished, the binding goes back to `draft` and keeps everything else

### Requirement: A saved form is a draft of its destination object (REQ-PIFO-008)

"Save and carry on later" SHALL save the destination object with OpenRegister metadata status `draft` (decision 180). The draft MAY miss required answers. It SHALL NOT hold a type-invalid answer; such an answer SHALL be refused on its field. Sending SHALL move the object out of `draft` through OpenRegister's submit service, with full validation; only then SHALL it get its reference and received moment. portaliq SHALL keep no draft store of its own. The draft's owner SHALL be `portal:<subjectRef>` (decision 195): only that signed-in resident reads, saves, sends or discards it. A draft SHALL read as gone, and be discarded, once the action's retention has passed since its last change (`updatedAt`): `draft.retentionDays`, 30 days by default (decision 201). Picked files SHALL be uploaded into the draft object. The site SHALL label a saved draft "Voorlopig" (decision 200).

#### Scenario: A resident saves half a form
- **GIVEN** a form into a dossiq case type whose description is required
- **WHEN** a signed-in resident saves it with the description still empty
- **THEN** a case exists in status `draft`, without a case reference shown to the resident and without a received moment
- **AND** no `portalDraft` object is written

#### Scenario: A wrong value is refused even in a draft
- **GIVEN** a date field mapped to a date-time property
- **WHEN** the resident saves a draft with a phone number in it
- **THEN** that field shows the error and nothing is saved

#### Scenario: Sending the draft makes it a received case
- **GIVEN** a saved draft that now holds every required answer
- **WHEN** the resident sends it
- **THEN** the case leaves `draft`, and the confirmation names its reference, received moment, term start and deadline

#### Scenario: A draft past its retention is gone
- GIVEN a draft last changed 31 days ago on an action without its own retention
- WHEN the resident opens the form
- THEN no draft is offered and the draft object is discarded

#### Scenario: Someone else's draft stays out of reach
- GIVEN a draft owned by `portal:sanne`
- WHEN another resident saves, reads or sends the same form
- THEN they see no draft and Sanne's draft is unchanged

#### Scenario: A file picked before saving stays with the draft
- GIVEN a resident picked a file and saved the form as a draft
- WHEN she sends the form later
- THEN the file is on the case and is not uploaded twice

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

#### Scenario: A case number alone does not open the reference page
- **GIVEN** a case created by a submit, with a case number that counts up
- **WHEN** someone who is not the signed-in subject on that case asks the reference page for that number
- **THEN** the page answers that the reference is not found
- **AND** an earlier `AANVRAAG-` or `WOO-` reference, copied to `externalReference` by the drain, still finds the case for anyone who holds it
- @e2e exclude Lookup rule; covered by PHPUnit (DestinationLookupTest)

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
