## ADDED Requirements

### Requirement: A menu block must show the portal's navigation in groups

The site MUST offer a public `siteNavigation` block that renders the items the header menu shows as one vertical list in groups: each signed-in app's pages under that app's label, the portal's own signed-in sections under "My overview", and each header menu under its title. The block MUST be a navigation landmark with an accessible name, MUST give each group a heading, MUST mark the current page with `aria-current="page"`, and MUST collapse its groups behind a button with `aria-expanded` at phone width. The shell MUST supply the groups; a placement MUST NOT be able to change where the links lead.

#### Scenario: A parent scans the side menu
- GIVEN the `wilgenboom` portal carries the block in its side region
- AND Fatima Hulstkamp is signed in and reads "Afwezigheid"
- WHEN the page renders
- THEN the menu shows "School" with the school app's pages, "Mijn overzicht" with her own sections and the site's pages under the menu title
- AND "Afwezigheid" is marked as the current page
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (groups and rendered block); live-checked on the primary-school instance

#### Scenario: A phone
- GIVEN the same page at phone width
- WHEN it renders
- THEN the groups are folded behind a "Menu" button that reports whether it is expanded
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (the button and its `aria-controls`); live-checked at 390px on the primary-school instance

### Requirement: A page with a menu block must leave the header menu out

When the page on screen carries a `siteNavigation` block in its side region or its main grid, the header MUST NOT render its menu, and MUST keep the logo, the language, the account controls and sign-out. When the side region holds the block, it MUST render as a column before and to the left of the content on pages and on the signed-in pages, and above the content at phone width. The portal's side region MUST apply to every page that does not state its own.

#### Scenario: The header without its menu
- GIVEN a page with the block in its side region
- WHEN it renders
- THEN the header shows no menu links and still shows "Ingelogd als ..." and the sign-out button
- @e2e exclude pinned by `tests/site-navigation.spec.mjs` (header rendered with and without its menu); live-checked on the primary-school instance
