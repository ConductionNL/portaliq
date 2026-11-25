# site-mijn-omgeving Delta: list-row-opens-its-record-page

## ADDED Requirements

### Requirement: A list row opens its collection's record page

When a resident chooses a row of a collection list, and another page of the same app is that collection's record page (it declares `record` or `records` on the collection and carries no list block on it), the site MUST open that page on the row's route (`<record page>/<id>`), as REQ-SMO-010 opens a linked record. A page that shows the row itself MUST keep it: the collection's own record page, and a page with a `detail` or `citizenCase` block on the collection.

#### Scenario: The assessor opens a shared portfolio from the overview
- GIVEN learniq's assessor overview lists `eaSharedPortfolios`
- AND its page `eaShare` is the record page of `eaSharedPortfolios`, out of the menu
- WHEN the assessor chooses Sanne's row under "Met u gedeeld"
- THEN the site opens `eaShare` on Sanne's share, with the entries it opens to him
- @e2e exclude pure function, covered by `node --test tests/open-record.spec.mjs` ("a list row opens its collection's record page when that is another page of the app"); the live walk is learniq's mbo-assessor-flows spec

#### Scenario: A list with its detail on the same page stays put
- GIVEN a page lists a collection and shows the chosen row in a `detail` block
- WHEN the resident chooses a row
- THEN the row is marked on that page and the detail block shows it
- @e2e exclude pure function, covered by `node --test tests/open-record.spec.mjs`
