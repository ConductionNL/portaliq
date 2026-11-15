---
status: proposed
---

# Spec: citizen-case-objection-and-complaint

## Purpose

A resident files a bezwaar or a klacht from the page of the case it is about. The case app declares the action and, for a bezwaar, the window; portaliq places it on the case page, fills in the case and keeps the guard. Board: `Zaak` on the Zuiddrecht canvas. Closes portaliq matrix rows `cmp-int-objection` and `act-complaint-on-case`.

## ADDED Requirements

### Requirement: A create action may declare a place on the case page (REQ-COC-001)

The contribution contract SHALL accept an `onCase` key on a create action, with `crossRef`, optional `order`, optional `window` (`from`, a date field of the case, and `days`, a positive whole number) and optional `closedText`. Portaliq MUST keep `onCase` only when `crossRef` names a key of the same action's `crossRefs`. A malformed `onCase` MUST be dropped while the action itself stays in the manifest.

#### Scenario: A well formed declaration is kept
- **GIVEN** dossiq's `createBezwaar` with `crossRefs.againstCaseId` and `onCase: {crossRef: "againstCaseId", window: {from: "decisionSentAt", days: 42}}`
- **WHEN** portaliq normalises the citizen contribution
- **THEN** the action carries `onCase` with the same values

#### Scenario: A placement without its guard is dropped
- **GIVEN** a create action with `onCase.crossRef: "caseId"` and no `crossRefs.caseId`
- **WHEN** portaliq normalises the contribution
- **THEN** the action is kept without `onCase`
- **AND** the case page offers no button for it

### Requirement: The case page offers the actions the server lists (REQ-COC-002)

The citizen case response SHALL carry `caseActions`: every create action of the resident's contributions whose `onCase.crossRef` points at the collection of this case, in `order`. Each entry MUST say whether it is open, and when closed carry the sentence to show. The case screen MUST draw open actions as buttons in the group "Meer acties voor deze zaak", before "Wijziging voorstellen" and "Aanvraag intrekken", and a closed action as its sentence without a button.

#### Scenario: A resident sees Bezwaar maken on a decided case
- **GIVEN** a case of the resident whose `decisionSentAt` is 2 October 2026 and today is 20 October 2026
- **WHEN** the resident opens the case page
- **THEN** the group "Meer acties voor deze zaak" shows "Bezwaar maken" and "Klacht indienen"

#### Scenario: A case app that declares nothing shows nothing
- **GIVEN** a contribution with no `onCase` on any action
- **WHEN** the resident opens one of its cases
- **THEN** the case page shows no bezwaar or klacht button and no sentence about them

### Requirement: The window is computed on the server (REQ-COC-003)

When `onCase.window` is declared, the action SHALL be open from the day after the `from` date through the last of `days` days, ending at 23:59 Europe/Amsterdam. Portaliq MUST treat a missing or unparseable `from` value as closed. The create route MUST refuse a submission outside the window with 409 `case_action_closed`, also when the screen showed the button.

#### Scenario: The six weeks have passed
- **GIVEN** a case whose `decisionSentAt` is 1 August 2026 and a bezwaar window of 42 days
- **WHEN** the resident opens the case page on 20 October 2026
- **THEN** "Bezwaar maken" is not shown and "De termijn voor bezwaar is voorbij." is shown

#### Scenario: A stale page cannot file late
- **GIVEN** a resident who opened the case page on the last day of the window and submits after midnight
- **WHEN** the create request arrives
- **THEN** the server answers 409 `case_action_closed` and no bezwaar is stored

#### Scenario: No decision date yet
- **GIVEN** a running case with no `decisionSentAt`
- **WHEN** the resident opens the case page
- **THEN** "Bezwaar maken" is not shown

### Requirement: The server fills in the case, and the guard still runs (REQ-COC-004)

The form opened from the case page SHALL NOT show the cross-reference field. A create request with `onCase` MUST re-read the case through the scoped reader, write the case's id into the declared cross-reference field over any value in the body, and then run `PortalCrossRefGuard` unchanged.

#### Scenario: The case is filled in
- **GIVEN** a resident on case 2026-0082 who presses "Bezwaar maken"
- **WHEN** they write their grounds and submit
- **THEN** the stored bezwaar's `againstCaseId` is the id of case 2026-0082

#### Scenario: A forged body is overwritten
- **GIVEN** a create request with `onCase` naming the resident's own case and a body whose `againstCaseId` names another person's case
- **WHEN** the request arrives
- **THEN** the stored bezwaar names the resident's own case

#### Scenario: Someone else's case is refused
- **GIVEN** a create request with `onCase` naming a case the resident cannot read
- **WHEN** the request arrives
- **THEN** the server answers 403 and nothing is stored

### Requirement: The resident gets a receipt on the case page (REQ-COC-005)

After a successful submission the case screen SHALL replace the form with a notice naming the reference from the submission receipt, and a line saying the bezwaar or klacht is now a case in Mijn zaken. The button MUST stay available while the action is open.

#### Scenario: A klacht is received
- **GIVEN** a resident who submits "Klacht indienen" on case 2026-0082
- **WHEN** the server answers with reference K-2026-0311
- **THEN** the case page shows "Uw klacht is ontvangen. Kenmerk K-2026-0311."
- **AND** "Klacht indienen" is still offered
