## ADDED Requirements

### Requirement: The result page travels in the address (REQ-CRG-009)

The search block SHALL page its results by number, SHALL request the chosen page with `_page` and `_limit`, and SHALL keep the page number in the page address, so a link opens the same page of the same results.

#### Scenario: A visitor shares page 3
- **GIVEN** a search for "fietspad" with five pages of results
- **WHEN** a visitor goes to page 3 and another visitor opens that page address
- **THEN** the request carries `_page=3` and the second visitor sees page 3 of the same results
- @e2e exclude the request shape is a pure URL build. Covered by `tests/federated-search.spec.mjs` ("sends the page", `_page`) and `tests/federated-search-reads-and-pages.spec.mjs` ("the search state reads every filter from the address").

### Requirement: The result count is announced (REQ-CRG-010)

When results load, the search block SHALL put the number of results, or that nothing was found, in a polite live region, so a screen reader announces it without moving focus.

#### Scenario: A search finds nothing
- **WHEN** a visitor searches for a term with no results
- **THEN** the live region reads "Zoekresultaten geladen. Geen resultaten gevonden." and focus stays in the search field
- @e2e exclude the sentence is state the block computes from the loaded total, with no flow of its own beyond a search. Covered by `tests/federated-search.spec.mjs` (the live-region status, "and says so once in the live region").

#### Scenario: A search finds twelve results
- **WHEN** a visitor searches for a term with twelve results
- **THEN** the live region names twelve results
- @e2e exclude same as "A search finds nothing". Covered by `tests/federated-search.spec.mjs` (the counted live-region sentence).
