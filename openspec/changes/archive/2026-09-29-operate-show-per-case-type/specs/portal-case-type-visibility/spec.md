---
status: proposed
---

# Spec: portal-case-type-visibility

## Purpose

An administrator decides, per portal, which case types residents see, and
the portal enforces it on every place a case type shows up. Portaliq matrix
row `dem-tnd-show-per-casetype` (TenderNed 402189).

## ADDED Requirements

### Requirement: An administrator hides a case type in one portal (REQ-OSC-001)

The admin app SHALL offer a "Case types" section on each portal's page listing the case
types the portal can name, each with a "Show in this portal" switch. Turning
it off SHALL store the case type in the portal's `hiddenCaseTypes`. Only an
administrator SHALL reach the section and its routes.

#### Scenario: An administrator hides an internal case type
- **GIVEN** an administrator and a portal whose case app serves "Omgevingsvergunning" and "Handhavingsdossier"
- **WHEN** they open the portal's page and, under "Case types", switch off "Show in this portal" for "Handhavingsdossier"
- **THEN** the switch stays off after a reload, and the warning "Residents with a case of this type will no longer see it here." was shown before saving
- e2e: `tests/e2e/operate-show-per-case-type.spec.ts`

### Requirement: A hidden case type does not reach residents (REQ-OSC-002)

For a portal with a hidden case type, "My cases" SHALL NOT list a case of that
type, a request for such a case's page SHALL get the same 404 as a case that
does not exist, and a request form for that type SHALL NOT render.

#### Scenario: The resident no longer sees the hidden type
- **GIVEN** a resident with one "Omgevingsvergunning" case and one "Handhavingsdossier" case, and "Handhavingsdossier" hidden
- **WHEN** they open "My cases"
- **THEN** they see only the "Omgevingsvergunning" case
- e2e: `tests/e2e/operate-show-per-case-type.spec.ts`

#### Scenario: A hidden case cannot be opened by its address
- **GIVEN** the same resident and the id of their hidden case
- **WHEN** they request that case's page
- **THEN** the response is the same 404 as for an unknown id
- @e2e exclude Server response comparison; pinned by ContributionControllerTest::testHiddenCaseTypeIs404

#### Scenario: Another portal is not affected
- **GIVEN** a second portal of the same organisation that does not hide the type
- **WHEN** the resident opens "My cases" there
- **THEN** both cases are listed
- e2e: `tests/e2e/operate-show-per-case-type.spec.ts`

### Requirement: Nothing is deleted by hiding (REQ-OSC-003)

Hiding a case type SHALL NOT change or delete any case, message or
declaration. Showing it again SHALL restore every case to the resident's
list.

#### Scenario: Showing it again brings the cases back
- **GIVEN** a hidden case type with a resident's case
- **WHEN** the administrator switches "Show in this portal" on again
- **THEN** the case is listed in "My cases" as before
- e2e: `tests/e2e/operate-show-per-case-type.spec.ts`
