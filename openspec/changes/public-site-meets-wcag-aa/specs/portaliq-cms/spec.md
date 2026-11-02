## ADDED Requirements

### Requirement: The public site meets WCAG 2.1 AA on every page state it serves (REQ-PWA-001)

Every page state the site renderer serves to an anonymous visitor SHALL pass axe-core run with the tags wcag2a, wcag2aa, wcag21a and wcag21aa with no serious or critical violation. This SHALL cover at least a widget grid page, a markdown page, the not-found page, the search page with results and facets, and a publication detail page. Every control on those pages SHALL be reachable and operable by keyboard alone, with a visible focus indicator. At a viewport of 320 CSS pixels wide no page SHALL scroll horizontally, except inside a table's own scroll container.

#### Scenario: The search page with facets
- **GIVEN** a portal whose search page shows results from two catalogues with facet checkboxes and a sort select
- **WHEN** axe-core checks the page against the WCAG 2.1 AA tags
- **THEN** it reports no serious or critical violation
- e2e: `tests/e2e/site-accessibility.spec.ts`

#### Scenario: A publication by keyboard
- **GIVEN** a visitor using only the keyboard on a publication detail page
- **WHEN** they tab through it
- **THEN** each link and download gets a visible focus and opens with Enter
- e2e: `tests/e2e/site-accessibility.spec.ts`
