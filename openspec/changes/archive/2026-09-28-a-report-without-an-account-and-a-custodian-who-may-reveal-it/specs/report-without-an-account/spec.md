---
status: proposed
---

# Spec: report-without-an-account

**Status:** proposed
**Scope:** portaliq (owner); the case app declares the custodian, the terms and what the reporter is shown
**Depends on:** `portal-intake-form-as-an-object` (the form); `portal-identity-and-the-organisations-cases` (the identity kinds this joins); `portal-auth-edge-session-hardening` (the session a code opens); `portal-contribution-contract` (the declaration and the events)

## Purpose

Someone reports wrongdoing without saying who they are, keeps a code, and
comes back with it to read the answer. Their identity, where they gave one,
is revealed only by the custodian the organisation named, on a motivated
request that is recorded. Requested by the dossiq competitor analysis,
ledger row 13.33, and obliged by the Wet bescherming klokkenluiders for a
municipality and for any employer with fifty people or more.

## ADDED Requirements

### Requirement: A report is accepted without an account (REQ-RWA-001)

The portal SHALL accept a report on a declared reporting surface without a
session, without an e-mail address and without any verification of the
reporter. It SHALL NOT create an account, SHALL NOT require a contact
detail to submit, and SHALL NOT refuse a report because no identity was
given.

#### Scenario: A stranger files a report
- **GIVEN** a portal with a reporting surface declared
- **WHEN** someone with no account submits a report
- **THEN** the report is created and no account exists for them
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: Contact details are optional
- **GIVEN** the reporting form
- **WHEN** it is rendered
- **THEN** no contact field is required to submit
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

### Requirement: The receipt code is issued once and is the only key back in (REQ-RWA-002)

On acceptance the portal SHALL issue a receipt code, show it once, and
state that it cannot be sent again. The code SHALL be generated from a
cryptographic source and stored only as a hash. The portal SHALL NOT offer
recovery of a lost code, and SHALL register every failed attempt to open a
thread with the throttler.

#### Scenario: The code is shown once
- **GIVEN** a report just accepted
- **WHEN** the reporter leaves the confirmation page and returns
- **THEN** the code is not shown again
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: A lost code is not recoverable
- **GIVEN** the portal's reporting surface
- **WHEN** a reporter looks for a way to recover a code
- **THEN** none is offered, and filing a new report is what is offered instead
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: Guessing is throttled
- **GIVEN** repeated attempts with wrong codes from one caller
- **WHEN** the attempts continue
- **THEN** each is registered with the throttler and the caller is delayed
- @e2e exclude Throttler state; covered by PHPUnit

### Requirement: The conversation runs against the code, both ways (REQ-RWA-003)

A valid code SHALL open the report's thread. The reporter SHALL be able to
read what the organisation wrote and to answer it, and the handler SHALL be
able to ask a question that reaches the reporter there. The thread SHALL
show only what the contribution declares visible to the reporter.

#### Scenario: The reporter reads the answer
- **GIVEN** a report whose handler has written a reply
- **WHEN** the reporter opens the thread with their code
- **THEN** the reply is shown
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: The reporter answers a question
- **GIVEN** an open thread
- **WHEN** the reporter writes an answer
- **THEN** it reaches the case and is recorded as the reporter's
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: An internal note stays internal
- **GIVEN** a note the contribution does not declare visible to the reporter
- **WHEN** the reporter opens the thread
- **THEN** the note is not shown
- @e2e exclude Visibility at the contract seam; covered by PHPUnit

### Requirement: Contact details are held apart from the report (REQ-RWA-004)

Where a reporter gives contact details, the portal SHALL store them as a
separate record referenced by the report, never as fields on it. A read of
the report SHALL NOT return them. A list, a search result, an export and a
notification SHALL NOT carry them.

#### Scenario: Reading the report does not read the reporter
- **GIVEN** a report whose reporter left an e-mail address
- **WHEN** a handler opens the report
- **THEN** the address is not shown
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: An export carries no identity
- **GIVEN** the same report
- **WHEN** the reports are exported
- **THEN** the export carries no contact detail for it
- @e2e exclude Export payload; covered by PHPUnit

### Requirement: Only the named custodian reveals, on a motivated request that is recorded (REQ-RWA-005)

A reveal SHALL require a request naming the report and carrying a
motivation, and SHALL be answered only by an identity holding the
custodian role the contribution declares. Every reveal SHALL write a
record naming who asked, the motivation, who allowed it, when, and what
was revealed, and SHALL raise a reveal event to the case app. A refused
request SHALL reveal nothing.

#### Scenario: The custodian allows a reveal
- **GIVEN** a motivated reveal request on a report with contact details
- **WHEN** the custodian allows it
- **THEN** the details are shown to the asker and a record names both of them
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: An administrator who is not the custodian cannot reveal
- **GIVEN** an instance administrator without the custodian role
- **WHEN** they try to reveal
- **THEN** it is refused and nothing is shown
- @e2e exclude Role refusal; covered by PHPUnit

#### Scenario: A request without a motivation is refused
- **GIVEN** a reveal request carrying no motivation
- **WHEN** it is submitted
- **THEN** it is refused and no record of a reveal is written
- @e2e exclude Request validation; covered by PHPUnit

#### Scenario: A refusal is recorded too
- **GIVEN** a motivated request the custodian refuses
- **WHEN** the refusal is made
- **THEN** the request and its refusal are on the record and nothing was revealed

### Requirement: The reporter sees where the statutory terms stand (REQ-RWA-006)

The portal SHALL render the acknowledgement term and the feedback term the
contribution declares, and where each stands for this report. It SHALL
hold no term of its own and SHALL NOT compute one from a constant in
portaliq.

#### Scenario: The acknowledgement term is visible
- **GIVEN** a report whose case type declares an acknowledgement term
- **WHEN** the reporter opens the thread
- **THEN** the term and the time left on it are shown
- e2e: `tests/e2e/a-report-without-an-account-and-a-custodian-who-may-reveal-it.spec.ts`

#### Scenario: A changed declaration changes what is rendered
- **GIVEN** a case type whose feedback term is changed
- **WHEN** the reporter opens the thread
- **THEN** the rendered term is the declared one
- @e2e exclude Declaration read-through; covered by PHPUnit

### Requirement: The reporting surface records nothing that identifies the reporter (REQ-RWA-007)

The reporting surface SHALL be excluded from visitor analytics by
declaration, SHALL record no client address against a report, and SHALL
make no request to a third-party challenge service. The challenge in front
of the form SHALL be the one the portal runs itself.

#### Scenario: No visitor row is written
- **GIVEN** analytics enabled on the portal
- **WHEN** someone opens the reporting surface and submits
- **THEN** no visitor row exists for that surface
- @e2e exclude Absence of a stored row; covered by PHPUnit

#### Scenario: Nothing leaves for a vendor
- **GIVEN** the challenge enabled on the reporting form
- **WHEN** the page is rendered and solved
- **THEN** no request leaves for a challenge vendor
- @e2e exclude Absence of an outbound call; covered by PHPUnit and the network policy test

#### Scenario: No address is stored with the report
- **GIVEN** a submitted report
- **WHEN** the stored report is read
- **THEN** it carries no client address
- @e2e exclude Stored payload; covered by PHPUnit
