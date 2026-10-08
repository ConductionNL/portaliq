---
status: proposed
---

# Spec: citizen-writes-on-their-own-case

**Status:** proposed
**Scope:** portaliq (owner); the case app declares the writable set, the window, the task and the public status label
**Depends on:** `partner-tasks-in-the-portal` (task delivery and return); `portal-contribution-contract` (the write path and events); `portal-intake-form-as-an-object` (the form an amendment is rendered from)

## Purpose

A citizen corrects their own request, adds the document that was missing,
and answers what the case worker asked, without phoning the desk. What
they may touch is declared on the case type. Requested by the dossiq
competitor analysis, round 4 cluster 48, decision D16.

## ADDED Requirements

### Requirement: The writable set comes from the case type (REQ-CWOC-001)

The portal SHALL render as writable only the fields the contribution
reports as writable for this audience, on this case, in its current
status. The portal SHALL keep no writable list of its own. A write to a
field outside that set SHALL be refused.

#### Scenario: Only the declared fields are editable
- **GIVEN** a case whose type flags two fields writable for the client audience
- **WHEN** the citizen opens the case in the portal
- **THEN** those two fields are editable and every other field is read-only
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A write outside the set is refused
- **GIVEN** the same case
- **WHEN** a write names a field that is not flagged writable
- **THEN** it is refused and nothing on the case changes
- @e2e exclude Refusal at the contract seam; covered by PHPUnit

#### Scenario: A closed field says why
- **GIVEN** a field that is writable only before a status the case has passed
- **WHEN** the citizen opens the case
- **THEN** the field is read-only and the reason is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

### Requirement: The applicant amends their own submitted request (REQ-CWOC-002)

A citizen SHALL be able to change the answers they gave, inside the
window the case type declares, rendered from the same form the request
was submitted with. The change SHALL be recorded on the case as the
citizen's. Outside the window the request SHALL be read-only.

#### Scenario: A correction lands without a phone call
- **GIVEN** a submitted request inside its amendment window
- **WHEN** the citizen corrects an answer and saves
- **THEN** the case carries the new answer and a record naming the citizen
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The window closes
- **GIVEN** the same request with the window closed
- **WHEN** the citizen opens it
- **THEN** the answers are read-only and the reason is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The history keeps both answers
- **GIVEN** an amended answer
- **WHEN** a case worker opens the case
- **THEN** the earlier answer and the amendment are both visible with their times

### Requirement: The citizen adds a document to their running case (REQ-CWOC-003)

A citizen SHALL be able to add a document to their own case from the
portal, through the file surface the case app declares. The document
SHALL be recorded as theirs. The portal SHALL add no second upload path
and SHALL NOT let a citizen replace or remove a document already on the
case.

#### Scenario: An aanvulling reaches the case
- **GIVEN** a running case open for documents
- **WHEN** the citizen adds a file from the portal
- **THEN** the file is on the case, recorded as the citizen's
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: Nothing already on the case is replaced
- **GIVEN** a case with a document the municipality added
- **WHEN** the citizen adds a document of the same name
- **THEN** both documents exist and neither is overwritten
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

### Requirement: A task is published to the citizen and its answer returns to the process (REQ-CWOC-004)

The case app SHALL be able to raise a task for the `client` audience with
a deadline. The portal SHALL show it on the citizen's case, accept the
answer, and return it to the process that raised the task. An answered
task SHALL NOT be answerable twice.

#### Scenario: The citizen answers what was asked
- **GIVEN** a task raised for the client audience on a case
- **WHEN** the citizen answers it in the portal
- **THEN** the answer reaches the process that raised it and the task reads answered
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A task is answered once
- **GIVEN** an answered task
- **WHEN** the citizen opens it again
- **THEN** the answer is shown and no second answer is accepted
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A deadline is visible before it passes
- **GIVEN** a task with a deadline
- **WHEN** the citizen opens their case
- **THEN** the deadline is shown beside the task

### Requirement: A citizen write raises its own event (REQ-CWOC-005)

Every write a citizen makes through the portal SHALL raise a portal write
event naming the case, the identity, the act and the fields. A write made
by a staff user SHALL NOT raise it. The event SHALL reach the case app so
a rule can fire on it.

#### Scenario: The citizen's write fires a citizen rule
- **GIVEN** a rule bound to the portal write event
- **WHEN** the citizen amends their request
- **THEN** the rule fires once, with the case and the identity
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: A staff write is not a portal write
- **GIVEN** the same rule
- **WHEN** a case worker changes the same field internally
- **THEN** the rule does not fire
- @e2e exclude Event absence on the internal path; covered by PHPUnit

### Requirement: The citizen reads the status vocabulary the case app supplied (REQ-CWOC-006)

The portal SHALL render the public status label and description the
contribution carries. It SHALL NOT hold a status vocabulary of its own
and SHALL NOT translate or rewrite the label it is given.

#### Scenario: The citizen sees the public label
- **GIVEN** a case whose status carries a public label
- **WHEN** the citizen opens the case in the portal
- **THEN** the public label is shown
- e2e: `tests/e2e/what-the-citizen-may-write-on-their-own-case.spec.ts`

#### Scenario: The portal invents no words
- **GIVEN** a case whose status carries the label the case app chose
- **WHEN** the case is rendered
- **THEN** the text shown is the label from the contribution, unchanged
- @e2e exclude Identity of the rendered string with the contribution payload; covered by PHPUnit
