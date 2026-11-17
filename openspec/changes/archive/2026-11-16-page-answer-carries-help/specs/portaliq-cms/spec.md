# portaliq-cms Delta: page-answer-carries-help

## ADDED Requirements

### Requirement: The page answer carries the page's help text

The content API's page answer (`GET /api/content/page`) SHALL carry `helpText`: the page's help text, trimmed and capped
as the pages list serves it, or an empty string when the page has none.

#### Scenario: PtStemgedrag

- **GIVEN** the published page "Hoe stemden de raadsleden" with a help text
- **WHEN** the site asks for that page
- **THEN** the answer carries the help text, and the page shows "Hulp bij deze pagina"
