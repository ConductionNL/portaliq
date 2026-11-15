## ADDED Requirements

### Requirement: Results filter by kind (REQ-SFK-001)

When the search response carries a `resultType` facet, the block SHALL offer a "Soort" filter with
"Publicatie", "Document" and "Onderwerp" and their counts. Choosing a kind SHALL send `resultType`
with it and SHALL write it to the address. Choosing none SHALL send no `resultType`. When the response
carries no `resultType` facet, the block SHALL offer no "Soort" filter.

#### Scenario: Only subjects
- **GIVEN** a search for "parkeren" answering 14 publications, 3 documents and 1 subject
- **WHEN** the visitor chooses "Onderwerp"
- **THEN** the request SHALL carry `resultType=subject` and the address SHALL carry it
- **AND** the one subject SHALL be listed

#### Scenario: An older catalogue gets no dead filter
- **GIVEN** an endpoint answering no `resultType` facet
- **WHEN** a visitor searches
- **THEN** no "Soort" filter SHALL be shown

### Requirement: Each kind links to its own page (REQ-SFK-002)

A result with `resultType` `publication` (or none) SHALL link to the publication page; `document` SHALL
link to `/document/{id}` and name its publication; `subject` SHALL link to `/onderwerp/{slug}` and show
its `publicationCount`. A row whose kind is unknown SHALL render as a publication.

#### Scenario: A document hit
- **GIVEN** a result with `resultType` `document`, id `d-42`, belonging to "Besluit Stationsweg"
- **WHEN** it renders
- **THEN** it SHALL link to `/document/d-42` and say it is part of "Besluit Stationsweg"
