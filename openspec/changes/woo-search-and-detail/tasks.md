# Tasks: woo-search-and-detail

- [ ] **T01**: `buildRequestUrl()` asks a facet per field in `facetFields`, appends selected values per field, and adds the period range (REQ-WSD-001, REQ-WSD-002). Verification: `tests/federated-search.spec.mjs`.
- [ ] **T02**: The block reads and writes `f.<field>` and `periodFrom`/`periodTo` in the page address, still reads the old `_facets` (REQ-WSD-001, REQ-WSD-003). Verification: `tests/federated-search.spec.mjs` on the pure helpers.
- [ ] **T03**: The facet column renders one group per field and the period group (REQ-WSD-001, REQ-WSD-002). Verification: `npm run build:site` within budget; Playwright on :8080 by the coordinator.
- [ ] **T04**: `searchQuery(state)` returns the C2 query object (REQ-WSD-004). Verification: `tests/federated-search.spec.mjs`.
- [ ] **T05**: `toDocuments()` and the documents section on the publication page (REQ-WSD-005). Verification: `tests/publication-documents.spec.mjs`.
- [ ] **T06**: `openspec validate woo-search-and-detail --strict`.
