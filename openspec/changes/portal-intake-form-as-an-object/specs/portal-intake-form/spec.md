---
status: proposed
---

# Spec: portal-intake-form

**Status:** proposed
**Scope:** portaliq (owner); buildiq publishes the form, opencatalogi publishes the catalogue, the case app declares its intake
**Depends on:** `embedded-intake-form` (anonymous submit, throttle); `portal-contribution-contract` (the create); `portaliq-cms` and `portal-page-designer` (pages, topics, layouts); `portal-identity-space` (the identity the applicant block reads)

## Purpose

A citizen finds the request they need in the portal and fills in the form
that belongs to it. The form is its own object, published for the case
type, and portaliq renders it. Requested by the dossiq competitor
analysis, round 4 cluster 51.

## ADDED Requirements

### Requirement: A portal page binds to a published form, not to a field list (REQ-PIFO-001)

A portal form page SHALL store a form binding of a type tuple, an
audience and an optional form name, and SHALL resolve it against the form
leaf at render time. The page SHALL render the fields, the order and the
presets the leaf returns, and SHALL keep no field list of its own. The
binding SHALL carry the intake settings for that form: address lookup,
prefill from earlier cases, a challenge, and the confirmation text.

#### Scenario: One case type carries two forms
- **GIVEN** a case type with a `client` form and a `supplier` form published
- **WHEN** a portal page bound to the `client` audience is rendered
- **THEN** only the fields of the client form are rendered, in its order
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: An edited form reaches the portal without a portal change
- **GIVEN** a rendered portal form page
- **WHEN** a field is added to the published form and the page is opened again
- **THEN** the new field is rendered
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: The confirmation text is the form's
- **GIVEN** a binding whose confirmation text is set
- **WHEN** a citizen submits the form
- **THEN** that text is shown on the confirmation
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: A binding that resolves to no form says so in the admin
- **GIVEN** a binding naming an audience for which no form is published
- **WHEN** an administrator opens the page in the CMS admin
- **THEN** the admin surface states that the binding resolves to no form
- **AND** the portal page renders a message and no fields

### Requirement: Intake can point at an externally hosted start form (REQ-PIFO-002)

A binding SHALL declare an `intakeKind` of `hosted` or `external`. An
`external` binding SHALL carry the URL of the start form, SHALL render a
start card that names the destination before the visitor leaves, and
SHALL NOT proxy or embed that form.

#### Scenario: The visitor is told where they are going
- **GIVEN** an external binding pointing at a form on another domain
- **WHEN** a visitor opens the request
- **THEN** the destination is named on the card before the link is followed
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: An external form is never proxied
- **GIVEN** the same external binding
- **WHEN** the request page is rendered
- **THEN** no request is made to the external host by the portal
- @e2e exclude Server-side absence of a call, observable only at the seam; covered by PHPUnit

### Requirement: The applicant block is prefilled from the signed-in identity only (REQ-PIFO-003)

A form rendered for a signed-in portal identity SHALL prefill the
applicant fields from that identity's own claims. A form rendered for a
visitor with no session SHALL leave those fields empty and SHALL NOT
indicate that a value was available.

#### Scenario: A signed-in citizen does not retype their name
- **GIVEN** a portal identity carrying a name and a contact address
- **WHEN** that citizen opens a form with an applicant block
- **THEN** the name and address are filled in
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: An anonymous visitor gets an empty block
- **GIVEN** no portal session
- **WHEN** the same form is opened
- **THEN** every applicant field is empty
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: Prefill never reads another identity
- **GIVEN** a signed-in citizen and a second portal identity in the same portal
- **WHEN** the form is rendered
- **THEN** no claim of the second identity appears in the response
- @e2e exclude Scoping of the render payload; covered by PHPUnit

### Requirement: A submission is validated against the form's schema before any create (REQ-PIFO-004)

The portal SHALL validate a submission against the schema of the form it
was rendered from, and SHALL refuse an invalid submission with an error
per field, before any contribution create is attempted.

#### Scenario: A missing required answer is a field error
- **GIVEN** a form whose schema requires a postcode
- **WHEN** a citizen submits without one
- **THEN** the postcode field carries the error and no create is attempted
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: Refusal happens before the case app is called
- **GIVEN** the same invalid submission
- **WHEN** it is posted
- **THEN** no contribution create reaches the case app
- @e2e exclude Ordering at the seam; covered by PHPUnit

### Requirement: The case is created asynchronously and the citizen gets a reference at once (REQ-PIFO-005)

A valid submission SHALL be accepted, queued and acknowledged with a
reference without waiting for the case to exist. The page behind that
reference SHALL read the real state of the submission, including a
create that failed, and SHALL NOT state that a case exists before one
does.

#### Scenario: The citizen is not held on the page
- **GIVEN** a valid submission
- **WHEN** it is posted
- **THEN** a reference is shown without waiting for the case
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: A failed create is visible, not silent
- **GIVEN** a queued submission whose create failed
- **WHEN** the citizen opens the reference
- **THEN** the page states that the request has not been registered yet and names what to do
- **AND** the submission is listed for the administrator with its reason
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

### Requirement: The citizen's entry point is composed content, listing the published catalogue (REQ-PIFO-006)

The entry point SHALL be portal content: pages, topics and layouts an
editor arranges without a code change. It SHALL list the request entries
opencatalogi publishes, and starting an entry SHALL open the form its
binding resolves to.

#### Scenario: An editor adds a topic without a developer
- **GIVEN** an editor with portal content rights
- **WHEN** they add a topic and place two requests under it
- **THEN** the topic and its requests appear on the entry point
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: An entry starts the right form
- **GIVEN** a catalogue entry whose binding names the client form of a case type
- **WHEN** a visitor starts it
- **THEN** that form is rendered
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`

#### Scenario: The catalogue is read, never kept
- **GIVEN** an entry withdrawn from the published catalogue
- **WHEN** the entry point is opened
- **THEN** the entry is gone without a portal change
- e2e: `tests/e2e/portal-intake-form-as-an-object.spec.ts`
