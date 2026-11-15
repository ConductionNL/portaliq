## ADDED Requirements

### Requirement: The dashboard shows traffic and availability of all portals together (REQ-DFP-001)

The dashboard SHALL show the visitors of the last 7 days and last month's availability of all published portals together as KPIs, a card with last month's availability per portal, and a card with page views, sessions, visitors and the share of engaged sessions of the last 7 days with the most viewed pages. The figures SHALL come from admin-only routes whose permission check runs before any record is read. Without traffic the engaged share SHALL be empty, never a division by zero. The existing KPIs SHALL stay.

#### Scenario: Two portals
- **GIVEN** the website had 15 visitors and Mijn Zuiddrecht 9 in the last 7 days
- **THEN** "Bezoekers, laatste 7 dagen" reads 24
- **AND** the most viewed pages of both portals are listed, most viewed first

#### Scenario: Not an administrator
- **WHEN** a user who is not an administrator asks an overview route
- **THEN** Nextcloud refuses it before any record is read
