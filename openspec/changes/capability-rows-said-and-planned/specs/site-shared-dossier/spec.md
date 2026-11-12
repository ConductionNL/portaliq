## ADDED Requirements

### Requirement: A shared dossier shows only what is public now (REQ-RSP-015)

The page a shared dossier link opens SHALL list only the documents in that dossier that are public at the moment it is opened. A document not yet public, or no longer public, SHALL not be listed and SHALL not be downloadable through the link.

#### Scenario: One document is not public yet
- **GIVEN** a shared dossier with three documents, one of which becomes public next week
- **WHEN** a visitor opens the link today
- **THEN** they see two documents, and the third cannot be reached through the link
