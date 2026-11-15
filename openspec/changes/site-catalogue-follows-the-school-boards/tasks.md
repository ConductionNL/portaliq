# Tasks: site catalogue follows the school boards

- [x] 1. `PublicCatalogueQuery`: facets by kind and by audience; `PublicCatalogue` keeps a news item's audience; endpoint `facetsBy`.
- [x] 2. `publicCatalogue.js` sends `facetsBy`; `catalogue.js` helpers (`facetsByOf`, `facetControl`, `chooseOne`, `metaLine`).
- [x] 3. `NlCatalogue.vue`: radios and menus, hidden label, rail search, meta card style, chevron, dated top line; styles; meta fields.
- [x] 4. Tests: `tests/site-look/catalogue-boards.spec.mjs`, `PublicCatalogueTest`.
- [ ] 5. Live check on :8092 (needs learniq's block props for the filters and styles).
