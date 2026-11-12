## ADDED Requirements

### Requirement: The CSV export follows what the page shows (REQ-RSP-009)

The traffic export SHALL hold the same portal, period and segment as the Traffic page it was started from.

#### Scenario: Export of a filtered page
- **GIVEN** the Traffic page set to portal "zuiddrecht", March and the segment "mobile"
- **WHEN** the administrator downloads the CSV
- **THEN** the file holds only the daily records for that portal, that month and that segment

### Requirement: A portal switches measurement off (REQ-RSP-010)

A portal SHALL be able to switch measurement off. While it is off, the collector SHALL refuse events for that portal and the site SHALL load no measuring script.

#### Scenario: Measurement off
- **GIVEN** a portal with measurement switched off
- **WHEN** a visitor opens one of its pages
- **THEN** no measuring script loads, and an event posted for that portal is refused and not stored
