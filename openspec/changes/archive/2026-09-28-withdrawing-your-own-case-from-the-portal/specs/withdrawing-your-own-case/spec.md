---
status: proposed
---

# Spec: withdrawing-your-own-case

**Status:** proposed
**Scope:** portaliq (owner); the case app declares whether a case may be withdrawn, until when, and onto which status
**Depends on:** `what-the-citizen-may-write-on-their-own-case` (the citizen write surface); `portal-status-transitions` (the server-fixed transition target); `portal-contribution-contract` (the declaration and the event)

## Purpose

An applicant ends their own request from the portal, without phoning the
desk. Whether they may, and until when, is the case type's decision.
Requested by the dossiq competitor analysis, ledger row 2.47.

## ADDED Requirements

### Requirement: The case type says whether a case may be withdrawn, and until when (REQ-WOC-001)

The portal SHALL offer withdrawal only where the contribution declares it
for this case, for this audience, in its current status. The portal SHALL
keep no list of withdrawable case types. Where the window has closed, the
portal SHALL show that withdrawal is no longer possible and why, and SHALL
NOT render an action that fails.

#### Scenario: A case type that allows it offers the action
- **GIVEN** a running request whose case type declares withdrawal open until a decision
- **WHEN** the applicant opens it in the portal
- **THEN** the withdraw action is offered
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: A case type that does not allow it offers nothing
- **GIVEN** a case type declaring no withdrawal
- **WHEN** the applicant opens the case
- **THEN** no withdraw action is shown and no withdrawal is accepted
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: A closed window says why
- **GIVEN** a request already decided, past its withdrawal window
- **WHEN** the applicant opens it
- **THEN** the action is absent and the reason is shown
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

### Requirement: Only the applicant, or an identity mandated for them, may withdraw (REQ-WOC-002)

A withdrawal SHALL be accepted only from an identity the contribution
reports as entitled to act on that case. Every other identity SHALL be
refused, including one that may read the case but holds no mandate to act
on it. A refusal SHALL change nothing on the case.

#### Scenario: A stranger is refused
- **GIVEN** a request filed by one applicant
- **WHEN** another portal identity submits a withdrawal for it
- **THEN** it is refused and the case is unchanged
- @e2e exclude Refusal at the contract seam; covered by PHPUnit

#### Scenario: A colleague with a read-only mandate is refused
- **GIVEN** an identity mandated to read an organisation's cases but not to act on them
- **WHEN** they submit a withdrawal
- **THEN** it is refused and the case is unchanged
- @e2e exclude Refusal at the contract seam; covered by PHPUnit

### Requirement: A withdrawal is confirmed, and may carry a reason (REQ-WOC-003)

The portal SHALL ask the applicant to confirm before the withdrawal is
sent, and SHALL state what withdrawal means in the words the contribution
supplies. It SHALL accept an optional free-text reason and SHALL send the
withdrawal only after the confirmation.

#### Scenario: Cancelling the confirmation changes nothing
- **GIVEN** an applicant on the confirmation step
- **WHEN** they cancel
- **THEN** the request is still running and nothing is recorded
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: A reason travels with the withdrawal
- **GIVEN** an applicant who types a reason and confirms
- **WHEN** the withdrawal is accepted
- **THEN** the reason is on the case beside the withdrawal
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

### Requirement: The resulting status comes from the case app, never from the client (REQ-WOC-004)

The status a withdrawal lands on SHALL be the one the contribution
declares. The portal SHALL apply it server-side and SHALL ignore any
status supplied in the request body. A case already withdrawn SHALL NOT
be withdrawn a second time.

#### Scenario: A tampered status is ignored
- **GIVEN** a withdraw action declaring its target status
- **WHEN** a request arrives naming a different status
- **THEN** the case lands on the declared status
- @e2e exclude Body tampering at the transition seam; covered by PHPUnit

#### Scenario: A second withdrawal is refused
- **GIVEN** a case already withdrawn
- **WHEN** a second withdrawal arrives
- **THEN** it is refused and the case is unchanged
- @e2e exclude Idempotency at the transition seam; covered by PHPUnit

### Requirement: The withdrawal is recorded and the request stays readable (REQ-WOC-005)

A withdrawal SHALL be recorded on the case with the identity, the mandate
it was made under where there is one, the time and the reason. The
original request SHALL remain readable in the portal after it. The portal
SHALL NOT delete anything and SHALL NOT offer the applicant a way to undo
the withdrawal.

#### Scenario: The withdrawn request is still readable
- **GIVEN** a withdrawn request
- **WHEN** the applicant opens it
- **THEN** the answers are shown read-only, with the withdrawal and its time beside them
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: The portal offers no undo
- **GIVEN** a withdrawn request
- **WHEN** the applicant looks for a way to reverse it
- **THEN** none is offered
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: The record names who withdrew it
- **GIVEN** a case withdrawn by an employee under an organisation mandate
- **WHEN** a handler opens the case
- **THEN** the identity and the mandate are on the withdrawal record

### Requirement: A withdrawal raises its own event (REQ-WOC-006)

Every accepted withdrawal SHALL raise a withdrawal event naming the case,
the identity, the mandate and the reason. The event SHALL reach the case
app so a rule can act on it. A status change made internally by a handler
SHALL NOT raise it.

#### Scenario: A rule fires on the citizen's withdrawal
- **GIVEN** a rule bound to the portal withdrawal event
- **WHEN** the applicant withdraws their request
- **THEN** the rule fires once, with the case, the identity and the reason
- e2e: `tests/e2e/withdrawing-your-own-case-from-the-portal.spec.ts`

#### Scenario: An internal withdrawal is not a portal withdrawal
- **GIVEN** the same rule
- **WHEN** a handler sets the withdrawn status internally
- **THEN** the rule does not fire
- @e2e exclude Event absence on the internal path; covered by PHPUnit
