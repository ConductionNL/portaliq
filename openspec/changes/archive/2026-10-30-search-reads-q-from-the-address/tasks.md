# Tasks: search-reads-q-from-the-address

### Task 1: Read and keep q
- **spec_ref**: `openspec/changes/search-reads-q-from-the-address/specs/portal-federated-search/spec.md#requirement-a-link-with-q-opens-the-search-for-that-term-req-srq-001`
- **files**: `src/site/lib/federatedSearch.js`, `tests/federated-search.spec.mjs`
- **acceptance_criteria**:
  - GIVEN `/zoeken?q=Lindelaan` WHEN the page opens THEN the field holds "Lindelaan" and the results are for it
  - GIVEN both `q` and `_search` WHEN read THEN `_search` wins
  - GIVEN a visitor who arrived with `q` WHEN she searches for "fietspad" THEN the address reads `q=fietspad`
  - GIVEN no `q` WHEN she searches THEN the address reads `_search=fietspad`, as before
- [x] Implement
- [x] Test (`node tests/federated-search.spec.mjs`; the new assertions fail on the old code)
