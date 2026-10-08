## ADDED Requirements

### Requirement: The roll-up counts searches that found nothing (REQ-PZR-001)

`TrafficRollup` SHALL add to each `portalTrafficDaily` record a `zeroResultSearches` dimension: per
search term, the number of `search` events of that day whose result count (`results` or
`params.results`) is the integer 0, sorted by count descending and capped like the other top lists.
An event with no readable result count SHALL NOT be counted as zero; the record SHALL carry
`searchesWithoutCount` with the number of such events. `portalTrafficDaily` SHALL declare both keys.
A roll-up portal SHALL sum its members' `zeroResultSearches` per term and their
`searchesWithoutCount`.

#### Scenario: Two terms found nothing
- **GIVEN** on one day the searches "parkeervergunning" (12 results), "parkeervergunnig" (0 results) twice and "hondenbelasting" (0 results) once
- **WHEN** the day is rolled up
- **THEN** `zeroResultSearches` SHALL hold "parkeervergunnig" with 2 and "hondenbelasting" with 1, and not "parkeervergunning"

#### Scenario: An unknown count is not a zero
- **GIVEN** a `search` event with a term and no result count
- **WHEN** the day is rolled up
- **THEN** the term SHALL NOT be in `zeroResultSearches` and `searchesWithoutCount` SHALL be 1

#### Scenario: A roll-up portal sums its members
- **GIVEN** two member portals that each recorded "afvalkalender" with 0 results once
- **WHEN** the roll-up portal's day is computed
- **THEN** its `zeroResultSearches` SHALL hold "afvalkalender" with 2

### Requirement: The Traffic page shows what the public did not find (REQ-PZR-002)

The Traffic page SHALL show, per portal and for the chosen period, a "Gezocht, niets gevonden" list
with each term, its count summed over the period, and a link that opens the portal's public search
with that term. When `searchesWithoutCount` is above 0 in the period, the list SHALL say how many
searches had no count. The daily records export SHALL include both keys.

#### Scenario: An editor sees the misspelling
- **GIVEN** the roll-ups of the first scenario for a week
- **WHEN** an editor opens the Traffic page for that portal and week
- **THEN** "parkeervergunnig" SHALL be listed with its count and a link to the public search for that term
