## ADDED Requirements

### Requirement: The case screen lists the resident's answers and asks for a case once

The case screen MUST list only the fields the writable set names, each with its state. It MUST NOT list other fields of the case as answers. A `citizenCase` block that shares its collection with a `detail` block on the same page MUST show nothing until a case is chosen. On its own it MUST still say "Select a case.".

#### Scenario: Mijn zaken asks once
- GIVEN dossiq's `mijnZaken` page has a `collection`, a `detail` and a `citizenCase` block on `mijnZaken`
- WHEN the resident opens the page without choosing a case
- THEN the page shows "Kies een item." and not "Kies een zaak."
- @e2e exclude pinned by `tests/site-collections.spec.mjs` ("a case screen under a detail card on its collection waits quietly for a case")

#### Scenario: Only answers are listed
- GIVEN a case whose writable set names `naam`
- AND the case row also carries `status` and `identifier`
- WHEN the case screen renders
- THEN it lists `naam` only
- @e2e exclude pinned by `tests/case-withdraw-screen.spec.mjs`
