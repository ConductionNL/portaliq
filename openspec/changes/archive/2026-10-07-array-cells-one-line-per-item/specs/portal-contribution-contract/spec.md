## ADDED Requirements

### Requirement: A cell holding a list shows one value per line

When a collection cell's value is a list of plain values (strings or numbers), the portal table and the site table MUST show each value on its own line, never joined with a comma. Values that are not lists MUST render as before.

#### Scenario: Report card grades read one per line
- GIVEN a report card row whose `gradeLines` is `["Rekenen: 7,9", "Taal: 8,3"]`
- WHEN a guardian opens "My child's report cards"
- THEN "Rekenen: 7,9" and "Taal: 8,3" appear on separate lines
- @e2e exclude pinned by `tests/array-cells.spec.mjs` (portal render and site formatter); live-checked on the primary-school instance
