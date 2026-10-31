## ADDED Requirements

### Requirement: A record page's back link keeps the portal and the route (REQ-RR3-003)

The link back to the list on a public record (a meeting, a decision, a council member) SHALL be the page's own address in its addressing, with the portal (`?portal=`) and the language (`?lang=`) kept and `?record=` removed.

#### Scenario: Back to all meetings
<!-- @e2e exclude Pinned by tests/public-records.spec.mjs ("the back link keeps the portal, the route and the language"). -->

- **GIVEN** a visitor on `/index.php/apps/portaliq/site?portal=zuiddrecht&route=/bestuur/vergaderingen&record=p1`
- **WHEN** they follow "Alle vergaderingen"
- **THEN** they land on `?portal=zuiddrecht&route=/bestuur/vergaderingen`

### Requirement: A publication ends the breadcrumb with its own title (REQ-RR3-004)

The publication detail block SHALL tell the page the publication's title once it is read, so the breadcrumb ends with that title; when the publication is not found it SHALL tell nothing and the trail keeps the page's words.

#### Scenario: A publication's trail
<!-- @e2e exclude Pinned by tests/publication-documents.spec.mjs ("the publication tells the page its title for the breadcrumb"). -->

- **GIVEN** the publication "Inspectierapport openbare verlichting wijk Lindelaan 2026" at `/publicatie/<id>`
- **WHEN** a visitor opens it
- **THEN** the breadcrumb reads "Home › Publicatie › Inspectierapport openbare verlichting wijk Lindelaan 2026"
