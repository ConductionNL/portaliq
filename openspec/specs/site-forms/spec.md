# site-forms Specification

## Purpose
Forms on a portal site: how an endpoint action declares its fields, what a form attached to a record asks and sends, where a refusal lands, and how a form on a page of its own is titled.

## Requirements

### Requirement: An endpoint action may declare a field's input (REQ-EAF-001)
A field config of an action MUST keep a declared `input` of `date`, `datetime`, `number` or `email` and MUST drop any other value. On a create or update action the input the schema's format gives MUST win.

#### Scenario: dossiq asks the Woo period as dates
@e2e exclude PHP: tests/Unit/Contribution/ActionConfigNormaliserTest.php
- GIVEN an endpoint action declares `periodeVan` with `input: date`
- WHEN the manifest is normalised
- THEN the field keeps `input: date` and the site asks it as day, month and year

### Requirement: A form attached to a record asks and sends what its action declares (REQ-EAF-002)
An attached action's listing MUST carry its `optionsProviders`, and the forward MUST send a field answered with several choices as a list of the chosen values.

#### Scenario: The Woo request on a dossier
@e2e exclude PHP: tests/Unit/Contribution/AttachedActionResolverTest.php; Node: tests/attached-actions.spec.mjs
- GIVEN dossiq's Woo request is attached to opencatalogi's dossier and declares the requester kind as choices
- WHEN a resident opens it on her dossier
- THEN the requester kind is asked as choices, and her chosen document kinds are sent as a list

### Requirement: A refusal of a form attached to a record lands on its field (REQ-EAF-003)
The answer of an attached action's forward MUST keep its status, and the app's `errors` and `invalid` per field MUST be shown on those fields, as on an endpoint form.

#### Scenario: dossiq refuses a date on the dossier page
@e2e exclude Node: tests/site-form-steps.spec.mjs
- GIVEN dossiq answers 400 with `errors.onderwerp` "Zeg waar uw verzoek over gaat."
- WHEN the resident sends the request from her dossier
- THEN the error summary links that field with that sentence, and the form does not say "Opslaan is niet gelukt"

### Requirement: A form on a page of its own does not repeat the page title (REQ-EAF-004)
An action block whose action label equals the title of the page it stands on MUST NOT print that label as a heading. Anywhere else it MUST.

#### Scenario: The Woo request's start page
@e2e exclude Node: tests/site-action-forms.spec.mjs
- GIVEN the page "Informatie opvragen (Woo-verzoek)" holds only that action's form
- WHEN a resident opens it
- THEN the title stands once, as the page's h1

### Requirement: An action field may ask for a count with a stepper

A field with `widget: count` MUST render as a − button, the number and a + button, the buttons named for a screen reader and disabled at `min` and `max`, with a line under it that a screen reader hears when it changes: the count and the unit in its singular or plural form, and " × " with `priceLabel` when one is declared. The value sent MUST be the whole number between `min` and `max`; with nothing chosen the form MUST send `min`.

#### Scenario: Three participants
- **GIVEN** the stepper starts at 1 with unit deelnemer/deelnemers and price label [PRIJS], max 3
- **WHEN** the employer presses + three times and − once
- **THEN** the line reads "2 deelnemers × [PRIJS]" and the field holds 2
- @e2e exclude component behaviour, covered by `node --test tests/site-count-field.spec.mjs`; the employer's booking form by learniq's proof run

### Requirement: A choice card may carry a second line

A `collection` options provider MAY declare `descriptionField`; each option MUST then carry that row field as its description when it is a non-empty string. A choice card MUST show an option's description as a second line under its label, and MUST draw no empty line for an option without one. A named-day card MUST read as a name over its date: "Vandaag" or "Morgen" over the date in the site's language, and a later day as its date alone.

#### Scenario: Who is absent, as cards with the group and teacher
- GIVEN the absence form's `learnerRef` provider with `labelField: givenName` and `descriptionField: groupLabel`
- WHEN a guardian of Vera ("Groep 7 · Meester Daan") and Sami ("Groep 4 · Juf Esra") opens the form
- THEN she sees a card "Vera" with "Groep 7 · Meester Daan" under it and a card "Sami" with "Groep 4 · Juf Esra" under it
- @e2e exclude component behaviour, covered by `node --test tests/site-form-widgets.spec.mjs` ("a child card carries its group and teacher as a second line", "a collection option carries the row field its provider names")

#### Scenario: When, as today and tomorrow
- GIVEN a `dateChoices` field with two named days, opened on Monday 5 October 2026
- WHEN the form renders in Dutch
- THEN the cards read "Vandaag" over "Maandag 5 oktober" and "Morgen" over "Dinsdag 6 oktober", then "Een andere dag"
- @e2e exclude pure function and component, covered by `node --test tests/site-form-widgets.spec.mjs` ("named days: a name over the date")

### Requirement: Today reads as the whole day

On a `dateChoices` field, today's card MUST read its date followed by ", de hele dag" in Dutch (", the whole day" in English). Tomorrow and later days MUST read their date alone.

#### Scenario: Sami is ill today
- GIVEN the absence form on Monday 5 October 2026
- WHEN his mother opens "Wanneer?"
- THEN the first card reads "Vandaag" over "Maandag 5 oktober, de hele dag"
- AND the second reads "Morgen" over "Dinsdag 6 oktober"
- @e2e exclude pure function, covered by `node --test tests/site-form-widgets.spec.mjs` ("named days: a name over the date")

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

### Requirement: A refusal by the record's own app MUST read as no longer possible (REQ-ORR-001)

When the app that owns a record refuses a portal create before it is stored (OpenRegister's creating hook stops the write), the portal MUST answer 409 `{error: "refused"}`, never 502, and MUST NOT repeat the app's text. A value the store refused MUST still answer 422 `invalid`, and a store that fails for any other reason MUST still answer 502 `write_failed`.

#### Scenario: A resident reacts on a closed consultation
@e2e exclude decidiq tests/e2e/portal-citizen-contribution.spec.ts (the owning app's spec runs it end to end)
- GIVEN decidiq refuses a reaction on a consultation whose status is closed
- WHEN a resident submits that reaction through the portal
- THEN the portal answers 409 `{error: "refused"}`
- AND the form says "Dit kan voor dit item niet meer."

#### Scenario: A store failure stays a failure
@e2e exclude PHPUnit ContributionControllerOwnerRefusalTest
- GIVEN OpenRegister fails on the write for another reason than a refusal
- WHEN a resident submits a form
- THEN the portal answers 502 `write_failed`

### Requirement: Saving a form first says what saving does (REQ-FSR-001)

On a form whose action can keep a draft, "Save and continue later" SHALL open a dialog before anything is saved. The dialog SHALL say that nothing is sent and no case is made yet, and how many days the answers are kept and until which date. It SHALL offer three choices:

- "Opslaan en stoppen" saves the draft and leaves the form the way cancelling does.
- "Doorgaan met invullen" closes the dialog.
- "Stoppen zonder opslaan" leaves the form without saving.

#### Scenario: Save and stop
@e2e exclude Unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a signed-in resident halfway through a form whose action keeps drafts for 30 days
- WHEN they choose "Save and continue later" and then "Opslaan en stoppen"
- THEN the draft is saved and the form is left as when cancelling

### Requirement: Reopening a form asks before it continues (REQ-FSR-002)

When a resident opens a form for which they have a live draft, the site SHALL ask before it fills anything in. The question SHALL name when the answers were last saved. "Verder met mijn antwoorden" SHALL restore the draft's answers. "Opnieuw beginnen" SHALL discard the draft and leave the form empty.

#### Scenario: Start again
@e2e exclude Unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a live draft of a form
- WHEN the resident opens the form and chooses "Opnieuw beginnen"
- THEN the draft is discarded and the fields are empty

### Requirement: The own area lists unfinished forms (REQ-FSR-003)

`GET /portal/api/drafts` SHALL list the signed-in subject's live drafts. Each entry SHALL carry its app, its action, the action's label, `updatedAt` and `expiresAt`. A draft past its retention SHALL NOT be listed. Mijn zaken SHALL show each draft as an "Onvoltooid formulier" card that names the form and the day it was saved, with a "Formulier afmaken" link to the form.

#### Scenario: A saved Woo-verzoek
@e2e exclude PHPUnit PortalDraftControllerTest; unit test in node: tests/form-save-and-resume.spec.mjs
- GIVEN a resident with a live draft of a create action labelled "Woo-verzoek"
- WHEN they open Mijn zaken
- THEN a card "Onvoltooid formulier" names "Woo-verzoek" and the day it was saved, and "Formulier afmaken" opens that form

### Requirement: The contact question form reads in the page's language

The contact block SHALL render its form in the page's language, with the same words as the other site forms, and an optional field's label SHALL read its name, a space and the optional mark.

#### Scenario: A Dutch resident opens the contact question form
@e2e exclude needs a signed-in portal session and pipelinq's action; covered by tests/contact-form.spec.mjs
- **GIVEN** a resident signed in on a Dutch page
- **WHEN** she opens `/contact/vraag` and the form has a field that is not required
- **THEN** the form says "Een veld zonder "niet verplicht" moet u invullen."
- **AND** that field's label reads "<name> (niet verplicht)"
