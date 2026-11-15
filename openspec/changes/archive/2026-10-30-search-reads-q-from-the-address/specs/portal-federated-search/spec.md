# portal-federated-search Specification (delta)

**Status**: planned
**Scope**: portaliq
**OpenSpec changes**:
- [search-reads-q-from-the-address](../../) (this delta)

## ADDED Requirements

### Requirement: A link with q opens the search for that term (REQ-SRQ-001)

The federated search block MUST read its term from `_search`, or from `q` when only `q` is on the address. When the visitor searches again, the block MUST write the term into the parameter it was read from, so a visitor who arrived with `q` keeps `q` in the address; otherwise it MUST write `_search`. An empty term MUST remove the parameter.

#### Scenario: A resident follows a link with q
- GIVEN a link to `/zoeken?q=Lindelaan`
- WHEN the resident opens it
- THEN the search field holds "Lindelaan" and the results are for Lindelaan
- @e2e exclude pinned by `tests/federated-search.spec.mjs` ("?q= opens the search for that term"); the block adopts `readSearchState()` on mount

#### Scenario: She searches again
- GIVEN the resident arrived with `?q=Lindelaan`
- WHEN she searches for "fietspad"
- THEN the address reads `q=fietspad` and reopening it shows the same search
- @e2e exclude pinned by `tests/federated-search.spec.mjs` ("a visitor who came with q keeps q")
