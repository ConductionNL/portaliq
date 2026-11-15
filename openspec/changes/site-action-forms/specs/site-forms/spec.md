## ADDED Requirements

### Requirement: A form must send each value in the type its field declares

The server MUST give a field whose schema type is `integer`, `number` or `boolean` that type as
its `valueType`. The site MUST send the value of such a field, of a number input and of a count
stepper as a JSON number or boolean, read a decimal comma as a point, and leave an empty number
or boolean field out of the body. The form MUST refuse a number field that holds no number before
it sends.

#### Scenario: Milan logs his hours
@e2e exclude Node: tests/site-action-forms.spec.mjs; PHPUnit SchemaInputHintNormaliserTest; checked live on the proof instance
- GIVEN the action `submitHourWeek` whose schema declares `hoursSubmitted` as a number
- WHEN Milan types "16" and sends the form
- THEN the body holds `hoursSubmitted: 16`, not `"16"`
- AND OpenRegister saves the week

#### Scenario: A word in a number field
@e2e exclude Node: tests/site-action-forms.spec.mjs
- GIVEN a number field "Uren die je goedkeurt"
- WHEN the resident types "acht" and sends
- THEN nothing is sent and the field says "Uren die je goedkeurt: vul een getal in, bijvoorbeeld 8 of 7,5."

### Requirement: An action that needs input must open its form before it sends

An action needs input when it has a field the resident fills in: a field in `fields` that is not
the `subjectField` or `rowField`, not set by the action's `set`, and not hidden. On an `action`
block, an endpoint action that needs input MUST draw its form and send the answers to its
endpoint through the portal. On a `cta` block, and on a greeting's button, an endpoint action, or a
create action without a `recordField`, that needs input MUST be a button that opens its form (a
create about a record keeps opening only with that record). Pressing that button MUST send
nothing. An action without fields to fill in keeps its single button.

#### Scenario: Petra approves a week of hours
@e2e exclude Node: tests/site-action-forms.spec.mjs; checked live on the proof instance
- GIVEN the trainer's cta "Uren goedkeuren" on the endpoint action `approveHourWeek`
- WHEN Petra presses it
- THEN the form asks for the week, the hours and a note, and nothing is sent yet
- AND when she sends it, the portal forwards `hourWeekId` and `hoursApproved` as a number

#### Scenario: Linda books places and fills in a birth date
@e2e exclude Node: tests/site-action-forms.spec.mjs; checked live on the proof instance
- GIVEN the greeting's "Medewerkers inschrijven" on `enrolEmployees` and the block `supplyBirthDate`
- WHEN Linda opens them
- THEN each shows its form (course and date with a count of participants; participant and birth date)
- AND sending it reaches learniq's endpoint with her answers

#### Scenario: The guardian reports an absence from the overview
@e2e exclude Node: tests/site-action-forms.spec.mjs
- GIVEN the greeting's "Afwezig melden" on the create action `createExcuseRequest`
- WHEN the guardian presses it
- THEN the absence form opens, and sending it creates the request

### Requirement: An update row action that needs input must open its form on the row

A `type: update` row action that needs input MUST open its form below the table, starting from
the row's own values, and MUST send only the answers in its PATCH. An update row action whose
values are all set by the action MUST still run at once.

#### Scenario: Milan fills in his self-assessment
@e2e exclude Node: tests/site-action-forms.spec.mjs; checked live on the proof instance
- GIVEN the row action "Nu invullen" (`fillInSelfAssessment`) on work process B1-K2-W1
- WHEN Milan presses it
- THEN a form asks for his estimate and nothing is sent yet
- AND when he picks "Goed" and sends, the PATCH holds only `selfAssessment: "goed"`

### Requirement: A refused answer must say in plain words which field to change

When OpenRegister refuses a value the portal wrote on a create or an update, the portal MUST
answer 422 with `invalid`, each refused field with its kind (`number`, `integer`, `boolean`,
`date`, `required` or `invalid`), naming only fields the portal wrote and never repeating the
store's text. The form MUST show a short message on each named field, from `invalid` and from
`errors`. A refusal that names no field MUST read by its status: 400 and 422 "Niet alles is goed
ingevuld. Kijk uw antwoorden na.", 403, 404 and 409 "Dit kan voor dit item niet meer.", anything
else "Opslaan is niet gelukt."

#### Scenario: The store refuses a number sent as text
@e2e exclude PHPUnit WriteRefusalTest, ContributionControllerRequiredFieldsTest
- GIVEN OpenRegister refuses with "Property 'notes' should be type 'number' but is 'string'"
- WHEN the create was for an action that wrote `notes`
- THEN the portal answers 422 `{error: "invalid", invalid: {notes: "number"}}`

#### Scenario: The app refuses without naming a field
@e2e exclude Node: tests/site-action-forms.spec.mjs
- GIVEN learniq answers 422 `{error: "incomplete"}`
- WHEN the form receives it
- THEN the form says "Niet alles is goed ingevuld. Kijk uw antwoorden na.", not "Dit is nu niet beschikbaar"
