# portaliq-cms Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-cms-content-model](../../changes/portal-cms-content-model/)
- [portal-website-scoping-and-auth](../../changes/portal-website-scoping-and-auth/)
- [portal-headless-content-api](../../changes/portal-headless-content-api/)
- [portal-shared-runtime](../../changes/portal-shared-runtime/)

## Purpose

Portaliq is the fleet's headless CMS (ADR-086). Its public content API is the
contract; the built-in site renderer (ADR-084) is one consumer of that API,
with no privileged path of its own. Content is scoped to a `website`, which
owns its domains, theme, authentication and locales, so one Organisation can
run several branded sites.

This document is the CANONICAL spec for the implemented subset. Requirements
still being designed live in the change deltas listed above until they ship.

Related: ADR-086 (headless CMS), ADR-084 (the built-in renderer), ADR-022 /
ADR-070 (OR-backed persistence), ADR-005 (fail-closed), ADR-082 (throttling).

## Requirements

### Requirement: A request MUST resolve to exactly one website, or to none

The serving `website` SHALL be resolved from an explicit site slug, or from the
request host taken from the trusted proxy configuration. An unresolved host
SHALL produce a not-found response. There SHALL be no default, first or
fallback website.

#### Scenario: An unknown host reveals nothing

- **GIVEN** a request whose host matches no website
- **THEN** the response is 404
- **AND** no site's title, theme or slug appears in the body
- @e2e `tests/e2e/site-multisite.spec.ts` (S7)

#### Scenario: A single configured site is still not a default

- **GIVEN** exactly one website exists
- **WHEN** a request arrives for a host it does not claim
- **THEN** nothing resolves
- @e2e exclude unit-tested — `tests/Unit/Service/WebsiteResolverTest.php::testASingleConfiguredSiteIsStillNotADefault`; the single-site case is where a "just use the only one" shortcut is most tempting and would go unnoticed until a second site existed

#### Scenario: A named site that does not exist does not fall through to the host

- **GIVEN** an explicit site slug that matches no website
- **THEN** nothing resolves, and the request is not served by whichever site
  owns the hostname it arrived on
- @e2e `tests/e2e/site-multisite.spec.ts` (S7b)

### Requirement: A custom domain MUST be verified before it serves

A domain SHALL serve only when its `verified` flag is true. An unverified
domain SHALL behave exactly as an unknown host.

#### Scenario: An unverified domain does not serve

- **GIVEN** a domain bound to a website with `verified: false`
- **THEN** requests for it return 404
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

#### Scenario: A verified domain does serve

- **GIVEN** a verified domain on the SAME website
- **THEN** requests for it are served
- **AND** this positive case runs alongside the refusal, because a verifier
  that refuses everything is indistinguishable from a working one when only
  the refusal is tested
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

### Requirement: All content MUST be scoped to a website

Every menu, page and glossary term SHALL belong to exactly one website, and
every read SHALL be filtered by it. An unscoped read SHALL return nothing
rather than everything.

#### Scenario: Two websites publishing the same route do not leak

- **GIVEN** two websites each publishing `/over-ons`
- **THEN** each host returns its own page and neither is reachable from the
  other
- @e2e `tests/e2e/site-multisite.spec.ts` (S5, S5b)

#### Scenario: An unscoped read returns nothing

- **GIVEN** a content read with no website
- **THEN** it returns empty rather than every site's content
- @e2e exclude unit-tested — `tests/Unit/Service/CmsReaderTest.php::testAnUnscopedReadReturnsNothing`; the failure mode is a cross-tenant leak that renders normally, so it is asserted at the seam rather than through the UI

### Requirement: A page body MUST be either a widget grid or markdown

`page.body.type` SHALL be `grid` or `markdown`. A markdown body SHALL be
stored and served as SOURCE. A grid body SHALL carry canonical manifest-v2
widget placements.

#### Scenario: Markdown is served as source

- **GIVEN** a markdown page containing a code fence and a table
- **WHEN** it is read through the API
- **THEN** the markdown source is returned, with no HTML introduced
- @e2e `tests/e2e/site-content.spec.ts` (S2)

#### Scenario: A grid page renders on the shared 12-column geometry

- **GIVEN** a grid page with two half-width widgets on one row
- **WHEN** it renders
- **THEN** they occupy the same row and different columns
- @e2e `tests/e2e/site-content.spec.ts` (S1)

### Requirement: Unpublished content MUST be indistinguishable from absent content

A draft page SHALL NOT be served, and its response SHALL be byte-identical to
that for a route that never existed.

#### Scenario: A draft and an unknown route answer identically

- **GIVEN** a draft page and a non-existent route
- **THEN** both return 404 with identical bodies
- **AND** a published route returns 200 — the control that distinguishes a
  working filter from one that hides everything
- @e2e `tests/e2e/site-security.spec.ts` (S4)

### Requirement: Public content reads MUST be cached, keyed by audience

Content responses SHALL be cached by website, kind, selector, locale AND
audience. Per-visitor responses SHALL be marked `private, no-store`; anonymous
published content SHALL be publicly cacheable. Cached entries SHALL be
invalidated on a content write, not by expiry alone.

#### Scenario: Anonymous and authenticated responses are marked differently

- **GIVEN** the same endpoint requested with and without an `Authorization`
  header
- **THEN** the anonymous response is publicly cacheable and the authenticated
  one is `private, no-store`
- @e2e `tests/e2e/site-security.spec.ts` (S8)

#### Scenario: The audience component is load-bearing

- **GIVEN** the audience removed from the cache key
- **THEN** the key test fails
- @e2e exclude unit-tested — `tests/Unit/Service/CmsReaderTest.php::testAudienceIsPartOfTheCacheKey`, observed failing with the component removed; a header assertion cannot show that two responses occupy different cache slots

#### Scenario: Creating a page clears the cached miss for its route

- **GIVEN** a route that has been requested and 404ed
- **WHEN** a page is created at that route
- **THEN** the next request serves it, without waiting for a TTL
- @e2e `tests/e2e/site-security.spec.ts` (S8b)

### Requirement: Markdown MUST NOT execute at a public origin

Markdown rendered by the site renderer SHALL be sanitised. No script,
`javascript:` href or event-handler attribute SHALL survive into the DOM.

#### Scenario: Hostile markdown is neutralised and the prose survives

- **GIVEN** a page whose markdown carries a script tag, a `javascript:` link
  and an `onerror` attribute
- **THEN** none executes and none remains in the DOM
- **AND** the surrounding prose still renders — the control that distinguishes
  sanitising from discarding
- @e2e `tests/e2e/site-security.spec.ts` (S9)

### Requirement: Only explicitly public widgets MUST render at a public origin

The site renderer SHALL mount only widget keys that are explicitly public.
Anything else SHALL render an inert placeholder without preventing the rest of
the page from rendering.

#### Scenario: A non-public widget degrades and the page survives

- **GIVEN** a grid page with one non-public widget among two public ones
- **THEN** the non-public one renders a placeholder and the others render
  normally
- @e2e `tests/e2e/site-security.spec.ts` (S10)

### Requirement: The site renderer MUST NOT depend on Nextcloud globals

The renderer SHALL boot and render with `OC`, `OCA` and `OCP` absent, reading
its configuration from the initial-state channel when present and from a
runtime global otherwise.

#### Scenario: The site renders with the globals deleted

- **GIVEN** the Nextcloud globals removed before the bundle runs
- **THEN** the title, menu and page still render
- @e2e `tests/e2e/site-security.spec.ts` (S11)

### Requirement: The content API MUST be sufficient without the built-in renderer

Every capability of the built-in renderer SHALL be reachable through the public
content API, and a consumer that is not the renderer SHALL be able to
reproduce a site from it alone.

#### Scenario: A Docusaurus build reproduces a site from the API alone

- **GIVEN** a website with a menu, markdown pages and glossary terms
- **WHEN** the Docusaurus plugin builds against the public API
- **THEN** the site is reproduced, and every request it made was a public
  content endpoint
- @e2e exclude proven by a separate consumer project rather than a browser
  test — `docusaurus-portaliq-proof` intercepts its own outbound calls and
  fails the run if any is not a `/api/content/` endpoint, which is the check a
  Playwright spec cannot make from inside the renderer
