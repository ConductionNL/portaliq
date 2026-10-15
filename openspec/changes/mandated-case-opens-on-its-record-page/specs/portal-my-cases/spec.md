## ADDED Requirements

### Requirement: A case read under a mandate opens on its record page

When the page a case opens on is a record page, choosing a case that "My cases" read under a mandate MUST open that case on the page, read under the mandate it carries. A row outside the resident's own list that carries no mandate MUST still open nothing.

#### Scenario: Linda opens her father's case
- GIVEN Linda Bakker holds a mandate for Henk Bakker
- AND dossiq's case page is a record page over `mijnZaken`
- WHEN she opens Henk's case from "Mijn zaken"
- THEN the case page shows Henk's case, read under the mandate
- @e2e exclude pinned by `tests/collection-loader.spec.mjs` "a record page opens a row picked under a mandate, and nothing else outside the list"; the live check on the rehearsal follows the merge

#### Scenario: A foreign row without a mandate
- GIVEN a record link to a case that is not in the resident's list and carries no mandate
- WHEN the record page opens
- THEN no case opens
- @e2e exclude pinned by `tests/collection-loader.spec.mjs`
