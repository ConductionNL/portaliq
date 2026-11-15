## ADDED Requirements

### Requirement: A table reads in its collection's default order

A `collection` block that renders as a table or as cards MUST show its rows in the block's `sort` when it declares one, else in its collection's `defaultSort`, else in the order the rows arrived. A table that renders one group per `groupByField` value MUST apply the same order inside each group. Rows without a value for the sort field come last, as they do for a block's `sort`.

#### Scenario: The newest absence first
- GIVEN learniq's `parentExcuseRequests` collection declares `defaultSort: { field: dateFrom, direction: desc }`
- AND the guardian's child has absences from 1 October, 5 October, 2 October and 25 September
- WHEN the guardian opens `/mijn/learniq/parentExcuseRequests`
- THEN the rows read 5 October, 2 October, 1 October, 25 September
- @e2e exclude pinned by `tests/mijn-lists.spec.mjs` ("a table reads in its collection's default order, and a block's own sort wins")

#### Scenario: A block's own sort wins
- GIVEN the same collection
- AND a `collection` block on it with `sort: { field: dateFrom, direction: asc }`
- WHEN the page renders
- THEN the rows read oldest first
- @e2e exclude pinned by `tests/mijn-lists.spec.mjs`

#### Scenario: A table per child
- GIVEN the same collection with `groupByField: learnerRef`
- AND two children with absences
- WHEN the page renders one table per child
- THEN each table reads newest first
- @e2e exclude pinned by `tests/mijn-lists.spec.mjs`

### Requirement: A detail card under its own table waits quietly for a row

A `detail` block that shares its collection with a `collection` block rendered as a table on the same page MUST show nothing until a row is chosen. A `detail` block without that table on the page MUST still say "Select an item.". A `detail` block that shares its collection with a `citizenCase` block MUST still say it too, because that case screen stays quiet in its favour.

#### Scenario: Nothing under the list of absences
- GIVEN learniq's `parentExcuseRequests` page has an `action`, a `collection` and a `detail` block on `parentExcuseRequests`
- WHEN the guardian opens the page without choosing a row
- THEN the page does not show "Kies een item."
- AND choosing a row shows its detail card
- @e2e exclude pinned by `tests/site-collections.spec.mjs` ("a detail card under the table of its own collection waits quietly for a row")

#### Scenario: Mijn zaken still asks once
- GIVEN dossiq's `mijnZaken` page has a `collection`, a `detail` and a `citizenCase` block on `mijnZaken`
- WHEN the resident opens the page without choosing a case
- THEN the page shows "Kies een item." once
- @e2e exclude pinned by `tests/site-collections.spec.mjs` ("a case screen under a detail card on its collection waits quietly for a case")
