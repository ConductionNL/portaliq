# Tasks: public-site-meets-wcag-aa

- [ ] **T01**: `tests/e2e/site-accessibility.spec.ts`: an axe case for the search page with results, facets and the sort select (FederatedSearchBlock), and one for a publication detail page (PublicationDetailBlock), with the same tags as S13 (REQ-PWA-001).
- [ ] **T02**: Keyboard case on the publication detail page: every link and download gets focus and opens with Enter (REQ-PWA-001).
- [ ] **T03**: Fix what T01 and T02 find in `src/site/components/FederatedSearchBlock.vue` and `src/site/components/PublicationDetailBlock.vue` (labels on facet checkboxes and the sort select, focus styles), each with a node test where the fix is logic.
- [ ] **T04**: Seed the example site with a search page and one publication so the cases run on a fresh instance; note in the PR which catalogue answers.
- [ ] **T05**: Run the suite against the Zuiddrecht example site; the axe report in the build PR.
- [ ] **T06**: `openspec validate public-site-meets-wcag-aa --strict`.
