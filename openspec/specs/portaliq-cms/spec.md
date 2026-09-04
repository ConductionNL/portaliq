# portaliq-cms Specification

**Status**: partially implemented — see "What is implemented" below
**Scope**: portaliq
**OpenSpec changes**:

- [portal-cms-content-model](../../changes/portal-cms-content-model/)
- [portal-scoping-and-auth](../../changes/portal-scoping-and-auth/)
- [portal-headless-content-api](../../changes/portal-headless-content-api/)
- [portal-shared-runtime](../../changes/portal-shared-runtime/)
- [contribution-landing-page-action](../../changes/contribution-landing-page-action/)

## Purpose

Portaliq is the fleet's headless CMS (ADR-086). Its public content API is the
contract; the built-in portal renderer (ADR-084) is one consumer of that API,
with no privileged path of its own. Content is scoped to a `portal`, which
owns its domains, theme, authentication and locales, so one Organisation can
run several branded portals.

> **Vocabulary.** The unit was called `website` in the first draft of this
> spec and in the register schema. It is `portal` everywhere now — schema id,
> property name, query parameter, service and test class. `website` in an
> older document means `portal`; there is no second concept.

This document is the CANONICAL spec for the implemented subset. Requirements
still being designed live in the change deltas listed above until they ship.

## What is implemented

Verified live on a disposable rig 2026-08-15 (`portaliq-p2-rig`, :8321), with
24 e2e tests and 21 unit tests. Every requirement below carries its evidence.

| Requirement | State |
| --- | --- |
| A request resolves to exactly one portal, or none | implemented |
| A custom domain is verified before it serves | **guard** implemented; the DNS TXT check that SETS the flag is not |
| All content is scoped to a portal | implemented |
| A page body is a grid or markdown | implemented |
| Unpublished content is indistinguishable from absent | implemented |
| Reads are cached, keyed by audience | implemented, with event-driven invalidation |
| Markdown does not execute at a public origin | implemented |
| Only explicitly public widgets render | implemented — via a local allow-list until nc-vue's registry carries `public` |
| The renderer does not depend on Nextcloud globals | implemented |
| The content API is sufficient without the built-in renderer | implemented — proven by a Docusaurus build |
| Editors have an admin surface for CMS content | implemented — declarative manifest pages; the publish-time validation rules are not |

**Not implemented, and specified elsewhere rather than left implied:**

- Per-portal authentication is declared in the schema and ENFORCED NOWHERE.
  Every portal currently behaves as `public` read-only, which happens to match
  the specified fail-closed default. That is a coincidence, not an
  implementation. The renderer now offers the sign-in door where a portal
  declares one; nothing guards what is behind it.
- The admin surface creates and edits content, but enforces none of the
  publish-time rules `portal-cms-admin-ui` specifies: a route is not checked
  for uniqueness within its portal, a portal with no page at `/` can still be
  published, and there is no domain-verification trigger.

> Per-portal theming WAS on this list and is not any more — the token
> stylesheet is resolved server-side and consumed by the renderer, and two
> portals now compute different colours. See the theme requirement below.

**A measured consequence of the authentication gap, stated because the
contribution bridge otherwise reads as under-delivering:** twelve apps ship a
`PortalContributionProvider` and all twelve conform to ADR-046 — conventional
FQCN, `getContribution()`, and eleven of twelve correctly do NOT implement
Portaliq's interface (the twelfth is Portaliq's own). But surveyed on
2026-08-15, **only Portaliq's own provider publishes an ANONYMOUS surface**;
every leaf app publishes exclusively to `citizen`, `client`, `supplier` or
`employee`, all authenticated audiences.

So the public contributions endpoint correctly returns one contribution today.
That is the contract working, not the bridge failing — and a visitor will see
the leaf apps' services only once authentication is enforced.

Related: ADR-086 (headless CMS), ADR-084 (the built-in renderer), ADR-022 /
ADR-070 (OR-backed persistence), ADR-005 (fail-closed), ADR-082 (throttling).

## Requirements

### Requirement: A request MUST resolve to exactly one portal, or to none

The serving `portal` SHALL be resolved from an explicit portal slug, or from
the request host taken from the trusted proxy configuration. An unresolved host
SHALL produce a not-found response. There SHALL be no default, first or
fallback portal.

#### Scenario: An unknown host reveals nothing

- **GIVEN** a request whose host matches no portal
- **THEN** the response is 404
- **AND** no portal's title, theme or slug appears in the body
- @e2e `tests/e2e/site-multisite.spec.ts` (S7)

#### Scenario: A single configured portal is still not a default

- **GIVEN** exactly one portal exists
- **WHEN** a request arrives for a host it does not claim
- **THEN** nothing resolves
- @e2e exclude unit-tested — `tests/Unit/Service/PortalResolverTest.php::testASingleConfiguredSiteIsStillNotADefault`; the single-portal case is where a "just use the only one" shortcut is most tempting and would go unnoticed until a second portal existed

#### Scenario: A named portal that does not exist does not fall through to the host

- **GIVEN** an explicit portal slug that matches no portal
- **THEN** nothing resolves, and the request is not served by whichever portal
  owns the hostname it arrived on
- @e2e `tests/e2e/site-multisite.spec.ts` (S7b)

### Requirement: A custom domain MUST be verified before it serves

A domain SHALL serve only when its `verified` flag is true. An unverified
domain SHALL behave exactly as an unknown host.

#### Scenario: An unverified domain does not serve

- **GIVEN** a domain bound to a portal with `verified: false`
- **THEN** requests for it return 404
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

#### Scenario: A verified domain does serve

- **GIVEN** a verified domain on the SAME portal
- **THEN** requests for it are served
- **AND** this positive case runs alongside the refusal, because a verifier
  that refuses everything is indistinguishable from a working one when only
  the refusal is tested
- @e2e `tests/e2e/site-multisite.spec.ts` (S6)

### Requirement: All content MUST be scoped to a portal

Every menu, page and glossary term SHALL belong to exactly one portal, and
every read SHALL be filtered by it. An unscoped read SHALL return nothing
rather than everything.

#### Scenario: Two portals publishing the same route do not leak

- **GIVEN** two portals each publishing `/over-ons`
- **THEN** each host returns its own page and neither is reachable from the
  other
- @e2e `tests/e2e/site-multisite.spec.ts` (S5, S5b)

#### Scenario: An unscoped read returns nothing

- **GIVEN** a content read with no portal
- **THEN** it returns empty rather than every portal's content
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

Content responses SHALL be cached by portal, kind, selector, locale AND
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

Markdown rendered by the portal renderer SHALL be sanitised. No script,
`javascript:` href or event-handler attribute SHALL survive into the DOM.

#### Scenario: Hostile markdown is neutralised and the prose survives

- **GIVEN** a page whose markdown carries a script tag, a `javascript:` link
  and an `onerror` attribute
- **THEN** none executes and none remains in the DOM
- **AND** the surrounding prose still renders — the control that distinguishes
  sanitising from discarding
- @e2e `tests/e2e/site-security.spec.ts` (S9)

### Requirement: Only explicitly public widgets MUST render at a public origin

The portal renderer SHALL mount only widget keys that are explicitly public.
Anything else SHALL render an inert placeholder without preventing the rest of
the page from rendering.

#### Scenario: A non-public widget degrades and the page survives

- **GIVEN** a grid page with one non-public widget among two public ones
- **THEN** the non-public one renders a placeholder and the others render
  normally
- @e2e `tests/e2e/site-security.spec.ts` (S10)

### Requirement: The portal renderer MUST NOT depend on Nextcloud globals

The renderer SHALL boot and render with `OC`, `OCA` and `OCP` absent, reading
its configuration from the initial-state channel when present and from a
runtime global otherwise.

#### Scenario: The portal renders with the globals deleted

- **GIVEN** the Nextcloud globals removed before the bundle runs
- **THEN** the title, menu and page still render
- @e2e `tests/e2e/site-security.spec.ts` (S11)

### Requirement: A contribution MUST be scoped to the portal it targets

A leaf app's contribution SHALL appear on a portal it targets and SHALL NOT
appear on one it does not. A contribution that declares no target SHALL appear
on every portal — the ADR-046 contract is unchanged, and a contribution is a
capability descriptor rather than tenant data. A malformed target SHALL fail
closed. The public endpoint SHALL serve only the ANONYMOUS aggregate.

#### Scenario: A declared target includes and excludes in one comparison

- **GIVEN** a contribution naming one portal
- **THEN** that portal receives it and another does not
- **AND** both are asserted from one input, because a filter that kept nothing
  would satisfy the exclusion alone
- @e2e exclude unit-tested — `tests/Unit/Contribution/PortalContributionFilterTest.php`; the rig has no multi-portal contribution fixture, and the failure mode is a cross-tenant surface that renders normally, so it is asserted at the seam and mutation-tested (both fail-closed branches observed breaking the suite when flipped)

#### Scenario: An untargeted contribution still reaches every portal

- **GIVEN** a provider written before portal targeting existed
- **THEN** it appears unchanged on every portal
- @e2e exclude unit-tested — `tests/Unit/Contribution/PortalContributionFilterTest.php::testAContributionWithNoTargetAppearsOnEveryPortal`

#### Scenario: The subject-scoped aggregate is never served publicly

- **GIVEN** the public contributions endpoint
- **THEN** it consults only the anonymous aggregate, and its response is
  publicly cacheable — a per-visitor aggregate in a shared cache slot is a
  leak that happens at the edge
- @e2e `tests/e2e/site-content.spec.ts` (S19)

### Requirement: A portal MUST offer only the sign-in routes it declares

The renderer SHALL derive its sign-in affordances from the portal's declared
`authentication.modes`, read from the public content API. A portal declaring
only `public` SHALL offer NO sign-in affordance. An unknown or malformed mode
SHALL produce none.

This requirement covers the DOOR, not a guard. Per-portal authentication is
still enforced nowhere — see "Not implemented" above — and nothing in the
renderer's auth surface may be read as gating content.

#### Scenario: A public-only portal offers no way to sign in

- **GIVEN** a portal declaring `modes: ['public']`
- **THEN** no sign-in affordance renders
- **AND** a portal declaring `digid` alongside it DOES offer one — the pair is
  asserted together, because a derivation that returned nothing at all would
  satisfy the first half by itself
- @e2e exclude unit-tested — `tests/site-auth.spec.mjs`; ten assertions over
  the mode derivation, including the mixed list, an unknown mode and a
  malformed value. The rig's portals are all `public`, so a browser test could
  only ever observe the absence

#### Scenario: A signed-out visitor still gets the portal

- **GIVEN** an auth edge that cannot be reached
- **THEN** the page still renders its public content, signed out
- @e2e `tests/e2e/site-content.spec.ts` (S21)

### Requirement: A portal's theme MUST change what a visitor sees

The serving portal's `theme` SHALL resolve to a themiq token stylesheet that is
loaded before the renderer boots. Two portals referencing different themes
SHALL compute different styles. A theme that does not resolve SHALL render
UNSTYLED rather than in another portal's brand.

#### Scenario: Two portals compute different styles

- **GIVEN** two portals referencing different themiq themes
- **WHEN** each is rendered
- **THEN** their COMPUTED heading colours differ — asserted on the computed
  value, never on the theme class name, because a class with no tokens behind
  it is exactly the state this requirement replaced
- @e2e `tests/e2e/site-multisite.spec.ts` (S20)

#### Scenario: An unresolvable theme renders unstyled, not misbranded

- **GIVEN** a portal whose theme names no shipped stylesheet
- **THEN** no token stylesheet is emitted and the page renders with its own
  defaults
- @e2e exclude unit-tested — `tests/Unit/Service/PortalThemeResolverTest.php`; a portal quietly wearing another municipality's colours renders perfectly and is invisible to a screenshot, so the refusal is asserted where the decision is made

### Requirement: The content API MUST be sufficient without the built-in renderer

Every capability of the built-in renderer SHALL be reachable through the public
content API, and a consumer that is not the renderer SHALL be able to
reproduce a portal from it alone.

#### Scenario: A Docusaurus build reproduces a portal from the API alone

- **GIVEN** a portal with a menu, markdown pages and glossary terms
- **WHEN** the Docusaurus plugin builds against the public API
- **THEN** the portal is reproduced, and every request it made was a public
  content endpoint
- @e2e exclude proven by a separate consumer project rather than a browser
  test — `docusaurus-portaliq-proof` intercepts its own outbound calls and
  fails the run if any is not a `/api/content/` endpoint, which is the check a
  Playwright spec cannot make from inside the renderer
