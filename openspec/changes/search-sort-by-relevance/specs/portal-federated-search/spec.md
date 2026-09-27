---
status: proposed
---

# Spec: portal-federated-search

## Purpose

A visitor searching the public publications can put the best matches first,
and the portal never offers an order the backend does not apply. OpenCatalogi
matrix row `srch-sort` and portaliq matrix row `sib-opencatalogi-srch-sort`.

## ADDED Requirements

### Requirement: A search with a term can be sorted by relevance (REQ-SSR-001)

The public search block SHALL offer "Meest relevant" when the search has a
term, SHALL use it by default for a new search with a term, and SHALL request
it as `_order[_relevance]=DESC` with `_fuzzy=true`. Without a term it SHALL
NOT offer it.

#### Scenario: A visitor gets the best matches first
- **GIVEN** a site with publications "Parkeervergunning aanvragen" from last year and "Jaarverslag 2025" from last week
- **WHEN** a visitor searches for "parkeervergunning"
- **THEN** the sort control shows "Meest relevant" and "Parkeervergunning aanvragen" is the first result
- e2e: `tests/e2e/search-sort-by-relevance.spec.ts`

#### Scenario: No term, no relevance option
- **GIVEN** a visitor on the search page without a term
- **WHEN** they open the sort control
- **THEN** "Meest relevant" is not among the options
- e2e: `tests/e2e/search-sort-by-relevance.spec.ts`

### Requirement: The block never shows a relevance order that was not applied (REQ-SSR-002)

When a response to a relevance order carries no `@self.relevance` on its
first row, the block SHALL remove the option for the visit, run the search in
the default order, and say "Sorting by relevance is not available here." once.

#### Scenario: A backend without ranking
- **GIVEN** an installation where OpenRegister has no trigram extension
- **WHEN** a visitor searches for "parkeervergunning"
- **THEN** the results come in the default order, "Meest relevant" is not offered, and the page says "Sorting by relevance is not available here."
- @e2e exclude Needs an instance without pg_trgm; pinned by a Vitest test on the block with a response lacking `@self.relevance`

### Requirement: The match score is available to assistive technology only (REQ-SSR-003)

When a result carries `@self.relevance`, its link SHALL be described to
assistive technology as "Match: {n} percent", and the number SHALL NOT be
shown visually.

#### Scenario: A screen reader user hears the match
- **GIVEN** a relevance-sorted result with a score of 82
- **WHEN** a screen reader reads the result link
- **THEN** it announces "Match: 82 percent"
- e2e: `tests/e2e/search-sort-by-relevance.spec.ts`
