## ADDED Requirements

### Requirement: A date typed or pasted whole fills day, month and year (REQ-RR3-001)

A date field asked as Dag, Maand and Jaar SHALL split a whole date typed or pasted into any of its boxes across the three: `d-m-yyyy` with `-`, `/`, `.` or a space between the parts, `yyyy-mm-dd`, and `ddmmyyyy`, and SHALL then put focus on Jaar. A box SHALL keep digits only. Typing two digits in Dag or Maand, or a separator after one digit, SHALL move focus to the next box. Deleting, Tab and Shift+Tab SHALL NOT move focus, and each box SHALL keep its own label.

#### Scenario: Pasting a date into Dag
<!-- @e2e exclude Clipboard paste in the node renderer; pinned by tests/site-form-fields.spec.mjs ("a whole date typed or pasted into Dag fills the three boxes"). -->

- **GIVEN** the Woo request's date field "Periode van"
- **WHEN** the resident pastes "01-01-2026" into Dag
- **THEN** Dag reads 01, Maand 01, Jaar 2026, the field's value is 2026-01-01, and focus is on Jaar

#### Scenario: Typing moves on after two digits
<!-- @e2e exclude Pinned by tests/site-form-fields.spec.mjs. -->

- **GIVEN** focus on Dag
- **WHEN** the resident types 15
- **THEN** focus moves to Maand, and a single 7 there stays until a separator or a second digit

### Requirement: A form asks for a draft only when its action can keep one (REQ-RR3-005)

A stepped form SHALL read or offer to save a draft only when its action declares `draft` and is a `type: create` action that names its register and schema, the actions the server keeps drafts of. The drafts endpoint SHALL answer 200 `{found: false}` when the action can keep a draft but the resident has none, or only one past its retention; it SHALL answer 404 only for an action it does not know.

#### Scenario: The Woo request form opens without a 404
<!-- @e2e exclude Pinned by tests/site-form-drafts.spec.mjs ("an endpoint action that declares a draft asks for none") and PortalDraftControllerTest::testNoDraftIsAQuietAnswerAndAnUnknownActionIsNotFound. -->

- **GIVEN** dossiq's `startWooVerzoek`, an endpoint action that declares `draft: {retentionDays: 30}`
- **WHEN** a resident opens it
- **THEN** no request is made to `portal/api/drafts/...` and no "Opslaan voor later" button shows

#### Scenario: A create action without a draft yet
<!-- @e2e exclude Pinned by PortalDraftControllerTest. -->

- **GIVEN** a create action that declares a draft, and a resident without one
- **WHEN** the form opens
- **THEN** the drafts endpoint answers 200 `{found: false}` and the form starts empty
