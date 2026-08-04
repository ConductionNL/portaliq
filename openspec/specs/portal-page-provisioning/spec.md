# portal-page-provisioning Specification

**Status**: done
**Scope**: portaliq
**OpenSpec changes**:

- [portal-page-provisioning](../../changes/archive/2026-07-25-portal-page-provisioning/) _(archived 2026-07-25)_

## Purpose

Lets an app (or an admin) provision a portal contribution — collections,
actions, and the UI pages composing them — as an OpenRegister `portalPage`
object, instead of shipping a PHP `PortalContributionProvider` class
(`OCA\{App}\Portal\PortalContributionProvider`, the FQCN convention
`PortalContributionRegistry` discovers today). Portaliq's own built-in
provider reads active `portalPage` objects and feeds them through the same
aggregation (`PortalContributionRegistry`) and normalisation
(`PortalManifestNormaliser`) path every PHP-provided contribution already
uses, so a data-provisioned page renders identically to a hand-coded one.

This capability also introduces **anonymous submission** as a first-class
mode: a collection or action may declare `anonymous: true`, making it
reachable by a caller with no bearer session at all — no DigiD/eHerkenning/
eIDAS, no portal account. Elevated trust (`minTrust: substantial|high`)
remains available for pages that need it and is unaffected; it is optional
elevation, not a precondition for anonymous intake. Related: ADR-046 (hydra,
Portaliq's canonical contract), ADR-022 (apps consume OR abstractions),
ADR-011 (schema property standards), ADR-005 (fail-closed security),
`portal-contribution-contract` (the sibling spec this one extends without
modifying).

## Requirements

### Requirement: An app MUST be able to provision a portal page as data

An app (or an admin) SHALL be able to provision a portal contribution by
creating an OpenRegister object of schema `portalPage` (register `portaliq`)
instead of shipping a PHP `PortalContributionProvider` class. Portaliq's
built-in provider SHALL read ACTIVE (`status: active`) `portalPage` objects
and surface them through the same aggregation and normalisation path every
PHP-provided contribution already flows through. A `draft` `portalPage`
object SHALL NOT appear in any aggregate.

#### Scenario: An app provisions a citizen-facing page with zero Portaliq code

- **GIVEN** an app creates a `portalPage` object with `audience: "citizen"`,
  `status: "active"`, one `collections` entry, one `actions` entry, and one
  `pages` entry composing them
- **WHEN** a subject authenticated for audience `citizen` requests their
  portal manifest
- **THEN** the response includes that contribution, rendered exactly as it
  would be had the app instead shipped a PHP provider class
- @e2e exclude no live IdP-backed citizen session in this dev environment
  (identity providers beyond `dev` are not wired end-to-end yet); the
  row→manifest conversion and aggregation path are covered by PHPUnit
  (`PortalContributionProviderTest::testGetContributionConvertsRowToManifestShape`,
  `PortalContributionRegistryTest::testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider`).
  Follow-up once a `portalPage`-provisioned demo page is deployed.

#### Scenario: A draft page is never exposed

- **GIVEN** a `portalPage` object with `status: "draft"`
- **WHEN** any subject (or an anonymous caller) requests a portal manifest
- **THEN** that page's collections, actions, and pages do not appear in the
  aggregate
- @e2e exclude status filtering happens before the provider ever sees a
  draft row — covered by PHPUnit
  (`PortalContributionProviderTest::testActivePortalPagesQueriesOnlyActiveStatus`)
  plus PortalObjectReader's own pre-existing, tested `filter` narrowing
  (`PortalObjectReaderTest`); no distinct UI surface from the zero-code
  scenario above.

### Requirement: Anonymous submission MUST be available without an identity provider

Portaliq SHALL accept an anonymous `type: create` submission — no bearer
session at all — for any collection/action a `portalPage` (or a PHP
provider's manifest) explicitly flags `anonymous: true`, writing the object
through OpenRegister WITHOUT stamping any subject/organisation ownership.
This capability SHALL NOT require DigiD, eHerkenning, eIDAS, or any other
identity provider to be configured or live.

#### Scenario: An anonymous citizen submits a public intake form

- **GIVEN** a `portalPage` action `{type: "create", register, schema,
  fields, anonymous: true}`
- **WHEN** an unauthenticated caller submits only the whitelisted fields to
  that collection's create route
- **THEN** the request succeeds (not 401) and the created object carries no
  subjectRef/organisation stamp
- @e2e exclude live citizen E2E (real browser, no session) is deferred —
  no deploy to the shared dev instance in this change. Covered end-to-end
  at the PHPUnit layer instead: `ContributionControllerTest::
  testAnonymousCreateSucceedsForAnAnonymousAction` (whitelist + write with
  no ownership stamp) and `PortalAuthMiddlewareTest::
  testNoBearerCreateToAnonymousDeclaredRoutePassesThrough` (the gate that
  lets the no-bearer request reach the controller at all). A seed
  `portalPage` object (`dev-citizen-intake`) ships this change so the real
  browser flow is exercisable once deployed — follow-up.

#### Scenario: A non-anonymous action still requires a bearer session

- **GIVEN** an action WITHOUT `anonymous: true`
- **WHEN** an unauthenticated caller submits to the same route
- **THEN** the request is rejected 401
- @e2e exclude regression invariant, not a new UI flow — covered by
  PHPUnit (`ContributionControllerTest::
  testCreateUnauthenticatedWithNoAnonymousActionIs403`,
  `PortalAuthMiddlewareTest::testNoBearerCreateToNonAnonymousRouteStillThrows`).

#### Scenario: An anonymous visitor can read the page layout before submitting

- **GIVEN** a `portalPage` with at least one `anonymous: true` entry
- **WHEN** an unauthenticated caller requests the portal manifest
  (`GET /portal/api/contributions`)
- **THEN** the response contains ONLY that contribution's anonymous-flagged
  collections/actions/pages (not any private sibling entry in the same
  contribution, and not any other audience's private contribution), instead
  of a 401
- @e2e exclude live citizen E2E deferred, same as the intake-form scenario
  above — covered by PHPUnit
  (`ContributionControllerTest::testIndexServesAnonymousAggregateWhenSubjectResolvesNull`,
  `PortalAuthMiddlewareTest::testNoBearerIndexWithAnonymousEntryPassesThrough`,
  `PortalContributionRegistryTest::testAggregateAnonymousSurfacesOnlyAnonymousEntriesAndDropsPrivateSiblings`).

### Requirement: Anonymous and elevated trust MUST NOT combine on one entry

Portaliq SHALL fail closed when one entry declares both `anonymous: true`
and a `minTrust` above `low` — a contradiction — by dropping `anonymous` on
that entry, falling back to requiring an authenticated, trust-checked
bearer.

#### Scenario: A malformed manifest entry cannot widen access

- **GIVEN** a manifest action `{anonymous: true, minTrust: "substantial"}`
- **WHEN** the manifest is normalised
- **THEN** the surfaced entry no longer carries `anonymous: true` — only a
  bearer-authenticated, sufficiently-trusted subject can invoke it
- @e2e exclude pure fail-closed sanitisation invariant, no UI surface —
  covered by PHPUnit (`PortalManifestNormaliserTest::
  testAnonymousIsDroppedWhenCombinedWithElevatedMinTrust`) and, fleet-wide,
  `PortalContributionRegistryTest::
  testAggregateAnonymousDropsEntryStrippedByMutualExclusion`.

### Requirement: Trust-elevated (non-anonymous) pages are unaffected

Elevated-trust, non-anonymous entries SHALL continue to require a resolved
bearer subject whose session trust satisfies the declared `minTrust`,
exactly as contract v2 already specifies — unchanged by this change.
DigiD/eHerkenning/eIDAS remain OPTIONAL trust elevation for pages that opt
into a higher `minTrust` — never a precondition for the anonymous or
`low`-trust surface this change adds.

#### Scenario: An elevated-trust action still gates on session trust, not on identity provider presence

- **GIVEN** a `portalPage` action with `minTrust: "substantial"` and no
  `anonymous` flag
- **WHEN** a bearer-authenticated subject whose session trust normalises to
  `low` invokes it
- **THEN** the request is rejected 403, identical to today's `minTrust`
  enforcement — unaffected by this change
- @e2e exclude pre-existing contract-v2 minTrust enforcement, not new
  behaviour — covered by the pre-existing
  `PortalContributionRegistryTest::testMinTrustFiltersCollectionsAndActionsFailClosed`
  and this change's
  `PortalContributionProviderTest::testContributionLevelMinTrustFillsEntriesLackingOwnMinTrust`.

## Non-Goals

- Frontend SPA routing/UX for anonymous form pages.
- `type: update`/`type: endpoint` anonymous actions.
- Anti-abuse/rate-limiting for anonymous writes.
- A bespoke admin UI for authoring `portalPage` objects (the standard OR
  objects UI/API covers it).

## Acceptance Criteria

- [x] `portalPage` schema exists in `lib/Settings/portaliq_register.json`
      with `title`/`description` on every property (ADR-011).
- [x] Portaliq's built-in provider reads active `portalPage` objects into
      the standard manifest shape.
- [x] `PortalContributionRegistry::aggregateAnonymous()` surfaces only
      `anonymous: true` entries, dropping private siblings.
- [x] `PortalAuthMiddleware` lets an anonymous-declared `create`/`index`
      request through without a bearer; every non-anonymous route is
      unaffected.
- [x] Anonymous writes carry no subject/organisation stamp; the mutual
      exclusion with elevated `minTrust` is enforced fail-closed.
