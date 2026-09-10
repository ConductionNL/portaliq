# Tasks: portals-open-site-action

Status: complete. Verified 2026-09-10 against the implementation on
`feat/woo-571-portals-open-site-action` (a8c5a3f) — every task's acceptance
criteria is asserted by `tests/open-portal-site.spec.mjs` (13 assertions),
`tests/e2e/portals-open-site.spec.ts`, or `npm run check:specs`, and the
change was exercised by hand on an isolated Nextcloud 34 rig with four seeded
portals covering all five scenarios.

## Implementation Tasks

### Task 1: The site-URL handler
- **spec_ref**: `openspec/changes/portals-open-site-action/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site`
- **files**: `src/lib/openPortalSite.js`, `src/customComponents.js`, `src/icons.js`
- **acceptance_criteria**:
  - GIVEN a row with slug `demo` WHEN the handler runs THEN it opens the app's site route with `?portal=demo` in a new tab with `noopener,noreferrer`
  - GIVEN an instance without URL rewriting WHEN the URL is built THEN it carries the `/index.php` prefix, because it comes from `generateUrl`
  - GIVEN a slug containing a character unsafe in a query string WHEN the URL is built THEN the slug is percent-encoded
  - GIVEN a row with no slug WHEN the handler runs THEN nothing is opened and an informational message is shown
- [x] Implement
- [x] Test

### Task 2: The manifest action and its strings
- **spec_ref**: `openspec/changes/portals-open-site-action/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Portals index page WHEN it renders THEN every row's action menu offers "Open portal" alongside the existing View / Edit / Delete entries
  - GIVEN the manifest WHEN `npm run check:specs` runs THEN it validates against the v2 manifest schema
  - GIVEN a Dutch browser session WHEN the menu renders THEN the label and the no-slug message are Dutch, with the `.js` catalogues regenerated from the `.json` ones
- [x] Implement
- [x] Test

### Task 3: End-to-end coverage
- **spec_ref**: `openspec/changes/portals-open-site-action/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site`
- **files**: `tests/open-portal-site.spec.mjs`, `tests/e2e/portals-open-site.spec.ts`, `package.json`
- **acceptance_criteria**:
  - GIVEN the Node spec WHEN `npm run check:specs` runs THEN the URL, encoding and no-slug cases are asserted
  - GIVEN an administrator in the browser WHEN the Playwright spec picks "Open portal" on the seeded portal THEN the popup URL carries `?portal=<slug>` and the site renders that portal's title
  - GIVEN hydra gate-19 WHEN it inspects the changed spec THEN each scenario is referenced from a Playwright spec file
- [x] Implement
- [x] Test

## Quality checklist

- No PHP touched, so no PHPUnit additions; the Node spec is the unit-level cover
- Playwright spec carries `@spec` references for gate-19 / gate-26
- Dutch (`nl`) and English (`en`) strings added, `.js` catalogues regenerated (`npm run l10n:build`)
- `npm run check:specs`, `npm run lint` and the hydra gates pass on the diff
- No `appinfo/info.xml` version bump — the release workflow owns that
