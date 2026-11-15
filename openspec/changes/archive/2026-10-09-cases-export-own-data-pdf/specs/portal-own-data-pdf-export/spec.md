---
status: proposed
---

# Spec: portal-own-data-pdf-export

## Purpose

A resident downloads their own information from the portal as a PDF: a list such as their payment statements, or one record such as their budget plan. The PDF holds exactly what the screen shows them. Requested by TenderNed 409958; closes portaliq matrix row `dem-tnd-export-pdf`.

## ADDED Requirements

### Requirement: A collection offers a PDF only when its app opts in (REQ-OPX-001)

A collection MAY declare `exportPdf: true`. The portal SHALL show "Download as PDF" above that collection's list and on the detail of one of its rows only when the collection declares it and the OpenRegister renderer is available. A collection that does not declare it SHALL offer no export, and its export routes SHALL answer 404.

#### Scenario: A resident downloads their statements
- **GIVEN** a statements collection that declares `exportPdf: true`
- **WHEN** a resident opens their statements page and presses "Download as PDF"
- **THEN** a PDF of the list they see downloads

#### Scenario: A collection without the flag offers nothing
- **GIVEN** a collection that does not declare `exportPdf`
- **WHEN** a resident opens it
- **THEN** no "Download as PDF" button is shown, and `GET .../export.pdf` answers 404

### Requirement: The export reads exactly what the screen reads (REQ-OPX-002)

`GET /portal/api/collections/{register}/{schema}/export.pdf` SHALL authorise and read exactly as `GET /portal/api/collections/{register}/{schema}` does, and `GET /portal/api/collections/{register}/{schema}/{id}/export.pdf` exactly as `GET /portal/api/collections/{register}/{schema}/{id}` does: the collection must be in the resident's own contributions, their trust must meet `minTrust`, and the rows must come from the subject-scoped read. A foreign or missing record SHALL get the same 404.

#### Scenario: Someone else's plan stays closed
- **GIVEN** a budget plan that belongs to another resident
- **WHEN** a resident requests its PDF export with their own session
- **THEN** the portal answers 404

#### Scenario: Low trust cannot export a substantial collection
- **GIVEN** a collection with `minTrust` `substantial`
- **WHEN** a resident signed in at `low` requests its export
- **THEN** the portal answers 403 and renders nothing

### Requirement: Only projected fields reach the PDF (REQ-OPX-003)

The PDF SHALL carry only the fields the collection projects for the screen, labelled with the collection's column labels where it declares them. The record export SHALL use the collection's detail fields where declared. The title SHALL be the collection's label, the organisation name and the export date.

#### Scenario: A hidden field stays out of the file
- **GIVEN** a collection whose projection leaves out an internal note field
- **WHEN** a resident exports it
- **THEN** the PDF has no column for the internal note

### Requirement: The portal renders through OpenRegister and handles its limits (REQ-OPX-004)

Portaliq SHALL render the PDF through OpenRegister's renderer and SHALL NOT carry a PDF library of its own. When the renderer is unavailable the export routes SHALL answer 503. When OpenRegister refuses the row count, the route SHALL answer 400 and the portal SHALL show "This list is too long for one PDF. Filter it first." Any other failure SHALL show "The PDF could not be made. Try again later."

#### Scenario: OpenRegister is missing
- **GIVEN** an instance where OpenRegister's rows renderer is not available
- **WHEN** the portal SPA loads the contributions
- **THEN** no collection reports `exportPdf`, and a direct export request answers 503

### Requirement: Every export is audited (REQ-OPX-005)

Every successful export SHALL be recorded in the portal audit trail with the `download` verb, the register and the schema, and the record id or the collection id.

#### Scenario: The export leaves a trace
- **GIVEN** a resident who exports their budget plan
- **WHEN** an administrator reads the portal audit trail
- **THEN** a `download` entry names the plan's register, schema and id
