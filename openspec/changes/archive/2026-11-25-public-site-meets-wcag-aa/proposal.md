---
kind: code
---

# Proposal: public-site-meets-wcag-aa

## Why

A public body must meet WCAG 2.1 AA on its public website (EN 301 549). The site renderer is tested for it: `tests/e2e/site-accessibility.spec.ts` runs axe-core with the WCAG 2.0, 2.1 and 2.2 A and AA tags on a grid page, a markdown page and the not-found page, walks the site by keyboard and checks the phone width. No requirement in `specs/portaliq-cms` says the public site must pass, only the signed-in portal has one ("The signed-in portal MUST meet WCAG 2.2 AA"). And the suite leaves out the two blocks that make the public reading room: the federated search block and the publication detail block, with their facet checkboxes and sort select.

## What changes

- **One requirement for the public site.** Every page state the site renderer serves to an anonymous visitor passes axe-core on the WCAG 2.1 AA tags with no serious or critical violation, is usable by keyboard alone with a visible focus, and does not scroll sideways at phone width.
- **The reading room is in the test.** The suite adds the search page with results and facets, and a publication detail page.

## Rows covered

- `sib-opencatalogi-pub-wcag` (built for three page states; the reading room blocks are this change). Board: Toegankelijkheid.
- `cmp-site-multisite` is already described by "A request MUST resolve to exactly one portal, or to none" in `specs/portaliq-cms`; `cmp-thm-languages` by "The public site MUST render its own words through a locale-aware translation layer" (`specs/supplier-portal`) and "The language switch offers the portal's locales and the choice reaches the content" (`specs/portaliq-cms`). This PR records those links in capabilities.json and adds no requirement for them. Translating the texts editors write is decided-no (`thm-content-i18n`).

## Out of scope

- The accessibility statement and the measurement in the admin. That is `site-accessibility-statement`.
- The painted contrast of the chrome under each theme. That is REQ-PTB-013 in `portal-theme-blocks-and-contributed-pages`.
