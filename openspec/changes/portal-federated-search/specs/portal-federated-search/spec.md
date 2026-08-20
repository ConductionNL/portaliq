# portal-federated-search Delta: portal-federated-search

**Status**: proposed
**Scope**: portaliq
**Depends on**: nothing blocking — see the proposal for why this is not blocked
by `openregister/openspec/changes/rbac-default-authenticated`
**OpenSpec changes**:

- [portal-federated-search](../../)

## Purpose

An anonymous visitor searches publications across every catalogue this instance
federates with, from a block an author places on a portal page. Portaliq runs no
search and applies no visibility rule: OpenCatalogi's `@PublicPage` federation
endpoint answers, and OpenRegister RBAC — invoked inside OpenCatalogi, against
schemas that all declare their authorization — decides what an anonymous caller
may see.

## ADDED Requirements

### Requirement: An anonymous visitor MUST be able to search federated publications

The site renderer SHALL offer a `federatedSearch` block that queries
OpenCatalogi's `/api/federation/publications` with free text, pagination and
facet buckets in a single request. It SHALL apply no visibility rule of its own
and SHALL NOT query OpenRegister directly.

The endpoint SHALL be configurable per placement, defaulting to the same
instance, so a portal can read another catalogue without a code change.

#### Scenario: A term matches a publication

- **GIVEN** a portal page placing the `federatedSearch` block
- **WHEN** an anonymous visitor searches for a term present in one publication
- **THEN** that publication is listed, with its title and a link
- @e2e `tests/e2e/site-federated-search.spec.ts` (S30)

#### Scenario: An empty term is not sent as an empty parameter

- **GIVEN** no term has been entered
- **THEN** the request omits `_search` entirely rather than sending `_search=`
- **BECAUSE** those are two different requests and only one of them means
  "everything"
- @e2e exclude Asserted in `tests/federated-search.spec.mjs`; a query-string
  shape is not observable from the rendered page.

#### Scenario: The block mounts at a public origin

- **GIVEN** the site bundle running with no Nextcloud session
- **THEN** the block issues its request with `fetch` and no CSRF token
- **AND** it imports nothing from `@nextcloud/*`
- @e2e `tests/e2e/site-federated-search.spec.ts` (S30b)

### Requirement: Every result MUST name the catalogue it came from

Each result SHALL display the value of its `@self.directory`, and a result
carrying none SHALL be labelled `local` rather than left blank.

**A federated list that does not say where a row came from is
indistinguishable from a local one.** That makes federation unverifiable from
the page, including for the operator checking whether it works at all.

#### Scenario: A federated row names its peer

- **GIVEN** results drawn from more than one directory
- **THEN** each row shows its own directory, not a single banner for the page
- @e2e `tests/e2e/site-federated-search.spec.ts` (S31)

### Requirement: A malformed federated row MUST NOT blank the list

A row that omits fields the renderer reads SHALL degrade to a titled entry.
One peer running an older schema SHALL NOT prevent the rows around it from
rendering.

#### Scenario: A row with almost no fields still renders

- **GIVEN** a result object with no name, no id and no `@self`
- **THEN** it renders as "Zonder titel" with directory `local`
- **AND** every other result in the same response still renders
- @e2e exclude Asserted in `tests/federated-search.spec.mjs`; producing a
  malformed row from a real federated peer is not reachable from an e2e run.

### Requirement: Facet buckets MUST be read in both dialects the endpoint speaks

The block SHALL normalise both `{data: {buckets: [{value, count}]}}` (object
and metadata fields) and `{buckets: [{key, results}]}` (OpenCatalogi's virtual
facets) into one bucket shape.

**Reading only one dialect renders an empty facet column, which on screen is
identical to "this field has no values"** — the defect presents itself as data
and is never reported as a bug.

#### Scenario: An object-field facet produces filters

- **GIVEN** `_facets[categories][type]=terms` in the response
- **THEN** the facet column lists each category with its count
- **AND** selecting one narrows the results
- @e2e `tests/e2e/site-federated-search.spec.ts` (S32)

### Requirement: A superseded request MUST NOT overwrite newer results

Each request SHALL carry a monotonic identifier, and a response whose
identifier is no longer current SHALL be discarded.

**A slow first request resolving after a fast second one replaces new results
with old ones.** It presents as "the page ignored my search" and is invisible
on a fast connection, which is where it is developed and tested.

#### Scenario: A slow response is discarded

- **GIVEN** a search whose response is outstanding
- **WHEN** a second search completes first
- **THEN** the first response, when it arrives, changes nothing
- @e2e exclude Requires deterministic response ordering; asserted by the
  sequence guard in the component rather than through the browser.

## Out of scope

### Filtering on the source directory

`@self[directory]=<peer>` returns `total: 0` on a corpus where all 711 rows
populate that field, and `_directory=<peer>` is accepted and ignored.
Measured 2026-08-20.

A filter control that silently empties the page is worse than no control: the
visitor concludes the catalogue is empty and nothing on the page contradicts
them. The directory is therefore shown per result and not offered as a filter.
The fix belongs in OpenCatalogi.
