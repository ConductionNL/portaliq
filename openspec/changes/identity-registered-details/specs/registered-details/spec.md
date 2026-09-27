---
status: proposed
---

# Spec: registered-details

**Status:** proposed
**Scope:** portaliq (owner); openregister supplies the person and company lookups
**Depends on:** `portal-identity-and-the-organisations-cases`, `portal-intake-form-as-an-object`, openregister `integration-person-lookup` and `integration-company-lookup`

## Purpose

A signed-in resident sees what the base registration holds about them, and a
business user sees what the KvK holds about their company. Both can ask the
organisation to correct it. Requested by the portaliq parity matrix rows
`cmp-id-brp`, `cmp-id-company`, `cmp-id-residents-at-address` and
`cmp-id-correct`.

## ADDED Requirements

### Requirement: A resident sees their own BRP record (REQ-IRD-001)

The portal SHALL show a signed-in resident the name, date of birth and address
the BRP holds for them, read through OpenRegister's person lookup at the moment
they open the section. The identifier SHALL come from the caller's own
`portalAccount` and never from the request. The portal SHALL NOT store the
record and SHALL NOT send the BSN or the raw lookup answer to the browser.

#### Scenario: A resident signed in with DigiD sees their details
- **GIVEN** a resident signed in with DigiD whose account holds a valid BSN as identity reference
- **WHEN** they open "My details" in the portal
- **THEN** they see their name, date of birth and address as the BRP holds them

#### Scenario: A request for someone else's details is not possible
- **GIVEN** a signed-in resident
- **WHEN** they call `GET /portal/api/identity/registered-details` with another person's BSN as a parameter
- **THEN** the parameter is ignored and only their own record is returned

#### Scenario: An account without a BSN shows why nothing is there
- **GIVEN** a resident whose broker supplied a pseudonym instead of a BSN
- **WHEN** they open "My details"
- **THEN** they read that the portal cannot show registered details for this way of signing in, and no lookup is made

### Requirement: A business user sees their company's KvK record (REQ-IRD-002)

The portal SHALL show a business user signed in with eHerkenning the trade
name, KvK number, legal form and registered branches the KvK holds for the
KvK number on their account, read through OpenRegister's company lookup.

#### Scenario: A business user sees the company record
- **GIVEN** a business user signed in with eHerkenning for KvK number 12345678
- **WHEN** they open "My details"
- **THEN** they see the trade name, legal form and branches the KvK holds for 12345678

### Requirement: An unavailable source says so (REQ-IRD-003)

When the lookup source is missing, unconfigured or down, the portal SHALL show
that the details cannot be shown right now, and SHALL NOT show an empty record
as if the registration held nothing. The log line SHALL name the cause and
SHALL NOT contain the identifier.

#### Scenario: The BRP source is down
- **GIVEN** the OpenConnector source `brp-haalcentraal` is not reachable
- **WHEN** a resident opens "My details"
- **THEN** they read "Your registered details cannot be shown right now." and the log holds the cause without the BSN

### Requirement: A resident can ask for a correction (REQ-IRD-004)

Where the portal administrator has bound a correction form to the portal, the
section SHALL offer "Report an error in these details", opening that intake
form. Where an address investigation form is bound, the address block SHALL
offer "Something wrong at this address?". An unbound link SHALL NOT render.

#### Scenario: A bound correction form is offered
- **GIVEN** a portal whose `registeredDetails.correctionFormBinding` names a published form binding
- **WHEN** a resident opens "My details"
- **THEN** they see "Report an error in these details", and following it opens that form

#### Scenario: No binding, no link
- **GIVEN** a portal with no correction form bound
- **WHEN** a resident opens "My details"
- **THEN** no correction link is shown

### Requirement: A resident sees how many people live at their address (REQ-IRD-005)

When OpenRegister can count the people registered at the resident's address
object, the address block SHALL show that number and no names. When it cannot,
the block SHALL say the number is not available.

#### Scenario: The count is shown without names
- **GIVEN** openregister answers a count of 3 for the resident's address object
- **WHEN** the resident opens "My details"
- **THEN** they see that 3 people are registered at their address, and no name of any of them
