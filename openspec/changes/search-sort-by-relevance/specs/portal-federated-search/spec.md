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

### Requirement: Every search with a term tolerates a misspelt title (REQ-SSR-004)

`buildRequestUrl()` SHALL send `_fuzzy=true` whenever the state has a non-empty term, whatever the
order. A search without a term SHALL NOT send it.

#### Scenario: A misspelt title is still found
- **GIVEN** a public publication titled "Parkeervergunning bewoners"
- **WHEN** a visitor searches "parkeervergunnig" in the default order
- **THEN** the request SHALL carry `_fuzzy=true` and the publication SHALL be among the results

### Requirement: A search that finds little offers a checked correction (REQ-SSR-005)

When a search with a term returns fewer than three results, the block SHALL ask
`GET /index.php/apps/portaliq/api/site/search/suggest?portal={slug}&q={term}`, which SHALL answer
`{suggestion: string|null, results: int}`. The server SHALL build the suggestion from a word list made
of the titles and summaries of the publications the anonymous public search of that portal returns,
refreshed daily by a background job and never from non-public content. For each word of the term not
in the list it SHALL pick the list word with the smallest edit distance, at most 1 for words of up to
five letters and at most 2 for longer words, preferring the more frequent word on a tie. It SHALL
answer a suggestion only when that corrected search returns at least one result, with that count,
and `null` otherwise. The block SHALL show "Bedoelde u: {suggestion}?" as a link that runs the
corrected search, and SHALL announce it in the results' live region. The route SHALL be public and
rate limited.

#### Scenario: A misspelling in a summary word gets a suggestion
- **GIVEN** public publications whose summaries contain "hondenbelasting", and none containing "hondenbelasing"
- **WHEN** a visitor searches "hondenbelasing" and gets 0 results
- **THEN** the block SHALL offer "Bedoelde u: hondenbelasting?" and following it SHALL show results

#### Scenario: No suggestion that finds nothing
- **GIVEN** a term whose closest correction also returns 0 results
- **WHEN** the suggest route is asked
- **THEN** it SHALL answer `suggestion` null and the block SHALL offer nothing

#### Scenario: Nothing non-public reaches the word list
- **GIVEN** a draft publication titled "Reorganisatie geheim"
- **WHEN** the word list is rebuilt and a visitor searches "geheimm"
- **THEN** no suggestion SHALL be "geheim"

### Requirement: The publisher can read how search ranks (REQ-SSR-006)

`src/site/lib/federatedSearch.js` SHALL export one declaration of the ranking it asks for: which
order is sent for a term, whether `_fuzzy` is sent, which fields OpenRegister matches fuzzily and
which exactly, and how federated rows without a score sort. `buildRequestUrl()` SHALL read its
parameters from that declaration. The portal's admin SHALL show a "How search ranks" section rendered
from the same declaration, in Dutch and English.

#### Scenario: The explanation follows the code
- **GIVEN** the declaration says titles match fuzzily and summaries and document text match exactly
- **WHEN** an administrator opens "How search ranks"
- **THEN** it SHALL say exactly that, and that federated results without a score come after scored results
