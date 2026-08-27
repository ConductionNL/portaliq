# portal-public-search Delta: portal-public-search

**Status**: proposed
**Scope**: portaliq
**Depends on**: `openregister/openspec/changes/rbac-default-authenticated` (blocking)
**OpenSpec changes**:

- [portal-public-search](../../)

## Purpose

An anonymous visitor searches a portal's content, with facets, including text
extracted from attached files. Visibility is decided by OpenRegister RBAC —
specifically the `public` group — and by the published predicate. Portaliq
adds no second visibility rule.

## ADDED Requirements

### Requirement: An anonymous visitor MUST be able to search a portal's public content

The public content API SHALL expose a search endpoint, scoped to the resolved
portal, that returns matching content with pagination. It SHALL delegate to
`ObjectService::searchObjectsPaginated()` with `_rbac: true` and
`_multitenancy: true`, and SHALL NOT apply a visibility rule of its own.

#### Scenario: A term in page prose is found

- **GIVEN** a published page containing a distinctive term
- **WHEN** an anonymous visitor searches for it
- **THEN** the page is returned, with its route
- @e2e `tests/e2e/site-search.spec.ts` (S23)

#### Scenario: An unresolved portal returns the shared 404

- **GIVEN** a host claimed by no portal
- **THEN** search answers with the same 404 body as every other content
  endpoint — search must not become the one route that reveals which hosts are
  claimed
- @e2e `tests/e2e/site-search.spec.ts` (S23b)

### Requirement: Search MUST NOT return what the caller may not read

A result set SHALL contain only rows an anonymous caller is entitled to read
under OR RBAC, and only rows whose status is published. The two filters are
independent and BOTH SHALL be applied.

#### Scenario: A draft page is not searchable, while a published one is

- **GIVEN** a draft page and a published page that both match the term
- **THEN** only the published one is returned
- **AND** the published one IS returned — asserted in the same test, because a
  search that returned nothing at all would satisfy the exclusion by itself
- @e2e `tests/e2e/site-search.spec.ts` (S24)

#### Scenario: A schema with no public group is not searchable anonymously

- **GIVEN** a schema carrying no `public` read rule
- **WHEN** an anonymous visitor searches for a term its rows contain
- **THEN** nothing from that schema is returned
- **AND** the same query as an entitled caller DOES return those rows, so the
  test distinguishes "correctly withheld" from "not indexed at all"
- @e2e exclude unit-tested — `tests/Unit/Service/PortalSearchTest.php`; the rig
  has no unmarked-schema fixture with public content, and the failure mode is a
  cross-tenant disclosure that renders as a normal result

#### Scenario: Content does not cross portals

- **GIVEN** two portals whose content matches the same term
- **THEN** each portal's search returns only its own
- @e2e `tests/e2e/site-search.spec.ts` (S25)

### Requirement: Search results MUST carry facets discovered from the result set

The response SHALL include facet buckets with counts, derived via OR's
`_facets` / `_facetable`. Facet counts SHALL be computed over the
RBAC-filtered set.

#### Scenario: Facets narrow the result set

- **GIVEN** a search returning content of more than one kind
- **THEN** the response carries a facet for kind, with counts
- **AND** applying it narrows the results to that kind
- @e2e `tests/e2e/site-search.spec.ts` (S26)

#### Scenario: A facet count never exceeds what the caller may read

- **GIVEN** a portal holding rows the anonymous caller may not read
- **THEN** no facet count includes them — a count computed before RBAC leaks
  the size of a set the visitor cannot see, which is a disclosure that shows up
  as a number rather than as content
- @e2e exclude unit-tested — `tests/Unit/Service/PortalSearchTest.php`; an
  off-by-N in a bucket count is not observable through the UI

### Requirement: Search MUST reach text extracted from attached files

Search SHALL pass `_content_search: true` so that `_search` widens to the text
OpenRegister extracts from attached files. A file hit SHALL be attributed to
the object that owns the file, because that is the thing with a route.

#### Scenario: A term that appears only inside an attached PDF is found

- **GIVEN** an object with an attached file whose extracted text contains a
  term appearing nowhere in the object's own fields
- **WHEN** an anonymous visitor searches for that term
- **THEN** the owning object is returned
- @e2e `tests/e2e/site-search.spec.ts` (S27)

#### Scenario: A file the caller may not read contributes no hit

- **GIVEN** an attached file on an object the anonymous caller may not read
- **THEN** its text produces no result — file text must not become a side
  channel around the object's own visibility
- @e2e exclude unit-tested — `tests/Unit/Service/PortalSearchTest.php`

### Requirement: The search endpoint MUST be publicly cacheable and rate limited

The endpoint SHALL be `#[PublicPage]`, SHALL carry an `#[AnonRateLimit]`, and
SHALL cap the result set. Its cache key SHALL include the audience, and a
per-visitor response SHALL be `private, no-store`.

#### Scenario: Search is throttled and capped

- **GIVEN** the search endpoint
- **THEN** it declares a volume ceiling per ADR-082 and refuses to return an
  unbounded result set
- @e2e `tests/e2e/site-search.spec.ts` (S28)
