# Tasks: portal-federated-search

> Public federated publication search as a site block (ADR-032 `kind: code`).
> Checkbox budget: 3 tasks × 2 = 6 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: The pure search helpers
- **spec_ref**: `openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-facet-buckets-must-be-read-in-both-dialects-the-endpoint-speaks`
- **files**: `src/site/lib/federatedSearch.js`, `tests/federated-search.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - `buildRequestUrl()` omits `_search` for an empty term rather than sending it empty, and asks for facet buckets in the SAME request as the results
  - `toBuckets()` reads BOTH the object-field dialect and OpenCatalogi's virtual-facet dialect, asserted with a fixture of each captured from the live endpoint
  - `toResult()` degrades a row with no name, no id and no `@self` to a titled entry instead of throwing
  - The test is wired into `check:specs`, which is what CI actually runs — `check:site-auth` existed and was never called by any workflow
- [x] Implement
- [x] Test

### Task 2: The block
- **spec_ref**: `openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications`
- **files**: `src/site/components/FederatedSearchBlock.vue`, `src/site/components/WidgetGrid.vue`
- **acceptance_criteria**:
  - Registered in `PUBLIC_WIDGETS` under `federatedSearch`; the map IS the gate, so registration and rendering stay one structure
  - The search box is the shared `CnSiteSearch`, not a second implementation of the reference's `ac-search-box` markup
  - No `@nextcloud/*` import anywhere in the block's import graph
  - Every result shows its `@self.directory`; a row without one reads `local`
  - A superseded response is discarded by sequence number
  - NO directory filter is offered — see the spec's Out of scope
- [x] Implement
- [x] Test

### Task 3: Measure what it costs and what it renders
- **spec_ref**: `openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-every-result-must-name-the-catalogue-it-came-from`
- **files**: `docs/portal-parity.md`, `tests/e2e/site-federated-search.spec.ts`
- **acceptance_criteria**:
  - The site bundle delta is measured with `NODE_ENV=production` against the same tree with only the registration reverted, and recorded — not estimated
  - `docs/portal-parity.md` carries the re-measured baseline; its 2026-08-15 figures were stale by 84% gzipped
  - An e2e run asserts a search returns rows AND that a row names a directory, against a live instance
- [x] Implement
- [ ] Test

> Task 3's e2e box stays open until the spec runs green against the demo rig.
> Ticking it before then would be the phantom tick ADR-029 exists to stop.
