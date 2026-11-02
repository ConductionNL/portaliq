## ADDED Requirements

### Requirement: A page can show the organisation's locations on a map (REQ-SLM-001)

The site SHALL offer a block that lists authored places, numbered in the order authored, each with its name and, when given, address, opening hours, a route link and its own links, beside a map that marks each place with a coordinate by the same number. With places of two or more kinds the block SHALL offer a chip per kind and one for all, and the list and the markers SHALL show only the chosen kind. The map SHALL request nothing from another site. A link that is not a site path, http(s), mailto or tel address SHALL be left out.

#### Scenario: Three places of the municipality
- **GIVEN** the Locaties page with the Stadskantoor, the Milieustraat and the Bibliotheek
- **THEN** the heading reads "3 locaties" and the map shows markers 1, 2 and 3
- **WHEN** the visitor chooses the chip "Afval"
- **THEN** only the Milieustraat is listed and marked
