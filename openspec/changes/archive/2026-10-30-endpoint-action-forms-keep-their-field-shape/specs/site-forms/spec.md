## ADDED Requirements

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
