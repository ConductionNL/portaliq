---
kind: code
---

# Proposal: portal-headless-content-api

## Summary

Ship the public content API that makes Portaliq a CMS rather than a portal:
site, menu tree, pages (grid or markdown), glossary and media, read by
website + route + locale, cached at the edge with the audience in the key and
invalidated on write.

Chain link 6 of `hydra/openspec/changes/portaliq-phase-two`. Implements
ADR-086 §§1, 9 — the contract every renderer, including Portaliq's own portal,
consumes.

## Motivation

Portaliq is the fleet's CMS, and headless is the primary mode: a customer with
their own front-end — a Docusaurus site, a custom application — consumes the
API, and the built-in portal is what we ship for those who do not.

That only holds if the API is complete. If any capability is reachable only
through the built-in renderer, the CMS is a portal with an API attached, and
every external consumer eventually needs an internal.

Caching is the other half. The public content path — hit by every anonymous
visitor on every page load — is uncached in the app this capability is moving
from: OpenCatalogi has exactly one cache in the entire codebase
(`createDistributed('opencatalogi_catalogs')`, keyed by catalog slug), and
`MenusController` / `PagesController` have none.

## Affected Projects

- [ ] `portaliq` — public content controller and service; response caching with
      an audience-inclusive key; invalidation on content write events;
      throttling on the anonymous read path (ADR-082).

## Design notes

**The cache key is `website + route + locale + audience`.** Audience is the
part that carries the risk: without it, an authenticated visitor's page is
served to everyone.

**Invalidation is event-driven**, on the content object's write. An editor who
publishes and then waits for a TTL will conclude the CMS is broken, and will be
right.

That is not a theoretical concern. The read cache stores **negative** results —
"no page at this route" is cached exactly like a page, or every 404 would hit
the database. During implementation (2026-08-15) the first page created after
the cache landed kept 404ing while the object plainly existed, and the only
visible symptom was a console error on a page that otherwise rendered
correctly. The regression test therefore asserts the *negative* case
specifically: request a route, get 404, create the page, request again with no
sleep. It has been observed failing with the listener disabled.

**Headers are stated once, deliberately.** Per-visitor responses are
`private, no-store`; anonymous published content is publicly cacheable. A CDN
sits in front in most deployments, and it can only be correct if we are.

**Markdown is served as markdown** — no HTML round-trip.

## Risks

- **A cache without the audience in its key is a data-leak, not a performance
  bug.** The test for it is written to fail when the component is removed, and
  that failure is observed before the key is trusted.
- **A public read path under load is new for this fleet.** It needs throttling
  and it needs the cache to actually hit — a cache that never hits looks
  identical to no cache at all except in the metrics.
- **"Headless" is easy to claim and hard to keep.** The conformance check is
  the Docusaurus plugin building against the API alone (link 11).
