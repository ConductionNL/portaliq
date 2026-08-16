# Tasks: portal-public-search

> Public portal search over OR object search, with facets and file text
> (ADR-032 `kind: mixed`). Checkbox budget: 5 tasks × 2 = 10 unindented
> `- [ ]` lines (cap 20).
>
> ⛔ BLOCKED ON `openregister/openspec/changes/rbac-default-authenticated`.
> Task 1 grants anonymous read to four schemas. While OR's default for an
> UNMARKED schema is still fail-open, shipping this makes the other 321
> unmarked schemas searchable anonymously too. Order is not a preference here.

## Implementation Tasks

### Task 1: Grant the `public` read group to the CMS schemas
- **spec_ref**: `openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-must-not-return-what-the-caller-may-not-read`
- **files**: `lib/Settings/portaliq_register.json`, `tests/Unit/Settings/RegisterAuthorizationTest.php`
- **acceptance_criteria**:
  - `page`, `menu`, `glossaryTerm`, `portal` each declare a `read` rule for group `public`; every OTHER schema in the register declares its authorization explicitly rather than relying on a default
  - A test asserts the rule PER SCHEMA by name, not by counting — a count passes while the wrong four are marked
  - `portalSession`, `portalAccount`, `portalSubmission`, `portalMessage`, `portalAuditEntry` are asserted NOT public. These carry visitor identity and submissions; they are the rows that must never appear in a public search, and the assertion exists so a later edit cannot quietly add them
  - The rule is `read` only — no anonymous write is granted anywhere
- [ ] Implement
- [ ] Test

### Task 2: The portal-scoped search endpoint
- **spec_ref**: `openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-a-portals-public-content`
- **files**: `lib/Controller/ContentController.php`, `lib/Service/PortalSearch.php`, `appinfo/routes.php`, `tests/Unit/Service/PortalSearchTest.php`
- **acceptance_criteria**:
  - One delegation to `searchObjectsPaginated()` with `_rbac: true`, `_multitenancy: true`, `_content_search: true`; NO visibility logic in Portaliq
  - Scoped to the resolved portal, and to published status, as two independent filters
  - An unresolved portal returns the byte-identical shared 404
  - `#[PublicPage]` + `#[AnonRateLimit]` + a hard result cap; audience in the cache key
- [ ] Implement
- [ ] Test

### Task 3: Facets, computed after RBAC
- **spec_ref**: `openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-results-must-carry-facets-discovered-from-the-result-set`
- **files**: `lib/Service/PortalSearch.php`, `tests/Unit/Service/PortalSearchTest.php`
- **acceptance_criteria**:
  - Buckets come from OR's `_facets`; the available fields from `_facetable`, so the rail cannot go stale against a changed schema
  - A test proves a facet count NEVER includes rows the caller may not read — asserted on the number, since this leak is a count rather than content
  - Applying a facet narrows the result set, and the unfiltered count is asserted in the same test
- [ ] Implement
- [ ] Test

### Task 4: File-text hits, attributed to the owning object
- **spec_ref**: `openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-must-reach-text-extracted-from-attached-files`
- **files**: `lib/Service/PortalSearch.php`, `tests/e2e/fixtures/seed-search.sh`, `tests/e2e/site-search.spec.ts`
- **acceptance_criteria**:
  - A fixture carries a term that exists ONLY inside an attached file's extracted text, so the test cannot pass on field matching
  - The result is the owning OBJECT with its route, never a naked chunk
  - A file attached to an object the caller may not read yields no hit — file text must not route around object visibility
- [ ] Implement
- [ ] Test

### Task 5: The search UI in the site renderer
- **spec_ref**: `openspec/changes/portal-public-search/specs/portal-public-search/spec.md#requirement-search-results-must-carry-facets-discovered-from-the-result-set`
- **files**: `src/site/components/SiteSearch.vue`, `src/site/App.vue`, `tests/e2e/site-search.spec.ts`
- **acceptance_criteria**:
  - Reached from the header on every page; results and facet rail render from the API response with no client-side filtering (a client filter would imply the server sent rows it should not have)
  - Empty state distinguishes "no results for this term" from "search is unavailable" — a broken deployment must not read as an empty portal
  - Keyboard reachable and axe-clean at serious/critical, like every other portal surface
- [ ] Implement
- [ ] Test
