## ADDED Requirements

### Requirement: A case read under a mandate offers no writes

A record page that shows a record read under a mandate SHALL NOT offer a write on that record: no form or button bound to the record, no add button on its documents and no propose-change action on its details. It SHALL keep showing what it reads. A mandate grants reading only (REQ-CMC-004), so this applies to every mandate.

#### Scenario: Acting for a parent
- GIVEN Linda acts for her father Henk under a mandate
- WHEN she opens Henk's case on its record page
- THEN she reads its steps, details, documents and history
- AND no add button, Bericht sturen, Bezwaar maken or Klacht indienen is offered
- @e2e exclude page composition; covered by `tests/collection-loader.spec.mjs`
