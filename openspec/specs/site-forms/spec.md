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
