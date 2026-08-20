---
kind: mixed
---

# Proposal: portal-public-search

## Summary

Give the portal a search box. An anonymous visitor searches the portal's
content — pages, glossary, and whatever else OpenRegister's RBAC says is
public — with facets to narrow by, and file text included because OR already
indexes it.

One `ObjectService::searchObjectsPaginated()` call does all of it. Portaliq
adds an endpoint on its existing public content API and a UI; it adds no
search engine, no index, and no second visibility rule.

## Motivation

The portal this replaces has search. This one has none — measured against
`:8306` on 2026-08-15 and recorded in `docs/portal-parity.md`. On a Woo portal
that is not a missing feature, it is the primary way a citizen finds anything:
the nav has four entries and the publication set has thousands.

## Why this is small, and where the work actually is

OpenRegister already provides every piece:

| Need | Already in OR |
| --- | --- |
| Full-text over objects | `_search` |
| Facets + facet discovery | `_facets`, `_facetable` |
| File text | `_content_search: true` → `ChunkMapper::searchByKeyword()` over extracted chunks |
| Visibility | `_rbac: true`, `_multitenancy: true` |
| Semantic / hybrid ranking | the configured search backend |

So the endpoint is a thin, portal-scoped delegation. **The work is not the
search — it is deciding what an anonymous caller may see**, and that decision
belongs to OR's RBAC, not to a second allow-list in Portaliq. A portal-side
list of searchable schemas would be a second source of truth that drifts from
the first, and the drift would be silent and in the unsafe direction.

## Decision: visibility is OR's `public` group, and nothing else

`PropertyRbacHandler::userQualifiesForGroup()` returns `true` for the group
`public` unconditionally, and `true` for `authenticated` only when a user is
present. So a read rule granting `public` is exactly "an anonymous visitor may
read this", expressed once, in the schema, where the data lives.

This change therefore adds that rule to the CMS schemas — `page`, `menu`,
`glossaryTerm`, `portal` — and adds NO visibility logic of its own.

## This change DEPENDS on `rbac-default-authenticated`

Adding the `public` group is necessary and **not sufficient**. Today a schema
that declares no authorization block at all is readable — measured in-process
on 2026-08-15: `/api/content/pages` returned **6 pages to an anonymous
caller** from a schema with no authorization block, and the fleet-wide survey
found **321 of 368 schemas (87%) declare none**.

Opening a public search path over RBAC while the default is fail-OPEN would
make those 321 schemas searchable by anonymous visitors. That is not a
theoretical risk; it is the arithmetic.

So `openregister/openspec/changes/rbac-default-authenticated` — absent
authorization means AUTHENTICATED-only, never public — is a **blocking
prerequisite**. This change must not ship before it.

## Affected Projects

- [ ] `portaliq` — a public `search` endpoint on the content API; a search UI
      in the site renderer; the `public` read rule on the four CMS schemas.
- [ ] `openregister` — none. Everything needed exists.

## Design notes

**Published-only is a separate filter from RBAC, and both are required.** The
NC-search probe on 2026-08-15 returned the draft page "Nog niet klaar" to an
admin — correctly, since an admin may read drafts. RBAC answers "may this
caller read this row"; it does not answer "is this row ready to be seen". The
public search filters `status` as well.

**Facets are discovered, not hardcoded.** `_facetable` reports which fields a
result set can be faceted on, so the facet rail reflects what the portal
actually holds rather than a list that goes stale when a schema changes.

**File hits are attributed to their owning object.** A chunk is a fragment of
a file attached to an object; a result that is a naked chunk is not something
a visitor can navigate to. `_content_search` already returns the owning
object, which is the thing with a route.

**The endpoint is publicly cacheable, so it must never see a subject.** Same
rule as the rest of the content API: audience is part of the cache key, and a
per-visitor result set in a shared CDN slot is a leak at the edge.

## Risks

- **The visibility decision is delegated, which means a wrong rule in a schema
  is a wrong answer in the portal.** That is the correct trade — one authority
  beats two — but it makes the `public` rule a security-relevant edit, and it
  is why it is asserted in tests per schema rather than assumed.
- **A facet is an aggregate over rows the caller may not read.** Facet counts
  must be computed after RBAC, not before, or the counts themselves leak the
  size of a set the visitor cannot see.
- **Search is the most expensive public endpoint on the portal.** It carries a
  rate limit and a hard result cap.
