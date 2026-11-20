## ADDED Requirements

### Requirement: A media item knows how many records use it (REQ-MU-001)

Each media item SHALL carry `usedOn`: how many records of its portal (the portal itself, its pages and its news) refer to it by `media:<id>` or by its content address. A record that refers to an item more than once SHALL count once. The count SHALL be refreshed at least hourly, writing only counts that changed. The Media list SHALL show the count and offer the pill "Nergens gebruikt" for items with a count of 0.

#### Scenario: An unused photo
- **GIVEN** a photo no page, news item or portal of Zuiddrecht refers to
- **WHEN** the hourly count has run
- **THEN** the photo reads 0 and is listed under "Nergens gebruikt"

#### Scenario: A photo on two pages
- **GIVEN** a photo used as hero image of one page and in the text of another
- **THEN** it reads 2
