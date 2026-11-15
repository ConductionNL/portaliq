---
status: proposed
---

# Spec: portal-intake-form

## Purpose

A resident fills in a published request form that shows only the questions
that apply to them, and can stop halfway and continue later. Portaliq matrix
rows `cmp-int-conditional` and `cmp-int-save-resume`.

## ADDED Requirements

### Requirement: A field's condition decides whether the resident sees it (REQ-ICQ-001)

Every portaliq renderer of a bound form SHALL show a field only while its
local-mode `visibleWhen` evaluates true over the answers so far, using
nextcloud-vue's `evaluateVisibleWhenLocal` or `CnFormPage`. Portaliq SHALL
NOT define a condition grammar of its own.

#### Scenario: A question appears when it applies
- **GIVEN** a published form where "Name of your partner" shows only when "Do you live together?" is "Yes"
- **WHEN** a resident on the intake page answers "Yes"
- **THEN** "Name of your partner" appears, and it disappears again when they answer "No"
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

#### Scenario: The embedded form behaves the same
- **GIVEN** the same form embedded on a municipality's website
- **WHEN** a visitor answers "No"
- **THEN** "Name of your partner" is not shown
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

### Requirement: The server skips a hidden field (REQ-ICQ-002)

`PortalFormValidator` SHALL evaluate each field's local-mode condition over
the submitted answers. A field whose condition is false SHALL NOT be required
and its answer SHALL NOT be accepted. The server evaluator SHALL pass the same
shared fixture as nextcloud-vue's predicate.

#### Scenario: A hidden required question does not block submitting
- **GIVEN** a required field "Name of your partner" hidden because the resident answered "No"
- **WHEN** the resident submits
- **THEN** the submission is accepted without an error on that field
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

#### Scenario: A hidden answer never reaches the case
- **GIVEN** a hand-crafted submission that answers "No" and also fills "Name of your partner"
- **WHEN** it is submitted
- **THEN** the accepted answers do not contain "Name of your partner"
- @e2e exclude Tamper assertion on a crafted body; pinned by PortalFormValidatorTest::testHiddenAnswerIsDropped

### Requirement: A condition the portal cannot check refuses the form (REQ-ICQ-003)

A bound form whose fields carry a `visibleWhen` in `endpoint` or `source`
mode SHALL resolve to no form with the reason `unsupportedCondition`, and the
binding preview SHALL tell the administrator "This form uses a condition the
portal cannot check. Change it to a condition on another answer."

#### Scenario: An administrator sees why the form is not shown
- **GIVEN** a binding whose form has a field with an `endpoint` condition
- **WHEN** an administrator opens the binding preview
- **THEN** the preview shows that sentence and the resident sees "This form is not available"
- @e2e exclude Resolver branch; pinned by PortalFormBindingResolverTest::testNonLocalConditionResolvesToNoForm

### Requirement: A bound form can be filled in on a site page (REQ-ICQ-004)

The public site SHALL offer an `intakeForm` widget that renders the form a
binding resolves to with `CnFormPage`, runs the portal challenge, submits
through `POST /portal/api/intake/submit` and shows the reference.

#### Scenario: A resident submits a request from a site page
- **GIVEN** a site page with the intake widget bound to a request form
- **WHEN** a resident fills it in and presses "Send"
- **THEN** the page shows the reference of their request
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

### Requirement: A resident can save and continue later (REQ-ICQ-005)

The intake widget and the embedded form SHALL offer "Save and continue
later", which stores only the visible answers as an OpenRegister journey run
and creates no case. A signed-in resident SHALL find their own draft when they
open the same form again. An anonymous visitor SHALL receive a resume code
once. Both SHALL be told until when the answers are kept, from the run's
declared retention.

#### Scenario: A signed-in resident continues the next day
- **GIVEN** a resident signed in with DigiD who saved a half-finished form
- **WHEN** they open the same form the next day
- **THEN** they see "You have unsent answers from {date}. Continue or start again." and continuing restores their answers
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

#### Scenario: A visitor resumes with the code
- **GIVEN** an anonymous visitor who saved and kept the resume code
- **WHEN** they enter the code on the same form in another browser
- **THEN** their answers are restored
- e2e: `tests/e2e/intake-conditional-questions-and-drafts.spec.ts`

#### Scenario: Saving creates no case
- **GIVEN** a resident who saves a draft
- **WHEN** the case app's register is queried
- **THEN** no case exists for it until they submit
- @e2e exclude Absence in another app's register; pinned by the draft route's PHPUnit test with the run service stubbed

#### Scenario: A wrong code says nothing
- **GIVEN** a visitor with a code that belongs to no run
- **WHEN** they enter it
- **THEN** the page says "We could not find answers for this code." exactly as for a code of another run
- @e2e exclude No-oracle response comparison; pinned by the resume route's PHPUnit test
