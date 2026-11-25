## ADDED Requirements

### Requirement: The not-found page offers a way on (REQ-SCN-004)

A route that does not exist or is not published SHALL render "Pagina niet gevonden" with "Foutcode 404", the site search box when the portal has search, and links to the homepage, to the resident area when the portal has one, and to the portal's contact route when that route exists. It SHALL ask the visitor to report a broken link through Contact. The answer SHALL stay identical for an unpublished page and a route that never existed.

#### Scenario: A mistyped address
- **WHEN** a visitor opens `/parkeren-vergunning`, which does not exist
- **THEN** the page shows the search box and links to De homepage, Mijn Zuiddrecht and Contact

#### Scenario: A portal without a contact page
- **WHEN** the portal has no `/contact` route
- **THEN** the not-found page shows no Contact link and no report sentence

#### Scenario: Draft and missing look the same
- **WHEN** a visitor opens an unpublished page and a route that never existed
- **THEN** both answers are identical
