## ADDED Requirements

### Requirement: An ended case shows its outcome with the organisation's explanation (REQ-COC-001)

A collection SHALL be able to declare `outcome: {labelField, descriptionField}`, each naming a field the collection projects; the normaliser SHALL drop an entry that names a field the collection does not project. When a case has ended and its `labelField` holds a value, the case page SHALL show that value under the heading "Uitkomst", with the `descriptionField` value under it when set, above the status steps. A value that is a uuid, a URL or a `$ref` object MUST NOT be shown as an outcome. A running case SHALL show no outcome, and a collection without `outcome` SHALL show none.

#### Scenario: A granted permit
- **GIVEN** dossiq's `mijnZaken` declares `outcome: {labelField: "resultPublicLabel", descriptionField: "resultPublicDescription"}`
- **AND** a resident's case has ended with `resultPublicLabel` "Vergunning verleend" and `resultPublicDescription` "Uw aanvraag voldoet aan het bestemmingsplan."
- **WHEN** she opens the case
- **THEN** the page shows "Uitkomst", "Vergunning verleend" and the explanation above the status steps
- e2e: `tests/e2e/case-outcome-on-the-case-page.spec.ts`

#### Scenario: A reference is never an outcome
- **GIVEN** an ended case whose `labelField` holds `3f1c2a9e-8b7d-4c1e-9a2b-5d6e7f8a9b0c`
- **WHEN** the case page renders
- **THEN** no outcome is shown
- @e2e exclude Rendering rule; pinned by tests/case-outcome.spec.mjs

#### Scenario: A running case has no outcome yet
- **GIVEN** a running case
- **WHEN** the resident opens it
- **THEN** no "Uitkomst" heading is shown
- @e2e exclude Rendering rule; pinned by tests/case-outcome.spec.mjs
