# portal-page-provisioning Specification

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-page-provisioning](../../changes/portal-page-provisioning/)

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

#### Scenario: A draft page is never exposed

- **GIVEN** a `portalPage` object with `status: "draft"`
- **WHEN** any subject (or an anonymous caller) requests a portal manifest
- **THEN** that page's collections, actions, and pages do not appear in the
  aggregate

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

#### Scenario: A non-anonymous action still requires a bearer session

- **GIVEN** an action WITHOUT `anonymous: true`
- **WHEN** an unauthenticated caller submits to the same route
- **THEN** the request is rejected 401

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

## Non-Goals

- Frontend SPA routing/UX for anonymous form pages.
- `type: update`/`type: endpoint` anonymous actions.
- Anti-abuse/rate-limiting for anonymous writes.
- A bespoke admin UI for authoring `portalPage` objects (the standard OR
  objects UI/API covers it).

## Acceptance Criteria

- [ ] `portalPage` schema exists in `lib/Settings/portaliq_register.json`
      with `title`/`description` on every property (ADR-011).
- [ ] Portaliq's built-in provider reads active `portalPage` objects into
      the standard manifest shape.
- [ ] `PortalContributionRegistry::aggregateAnonymous()` surfaces only
      `anonymous: true` entries, dropping private siblings.
- [ ] `PortalAuthMiddleware` lets an anonymous-declared `create`/`index`
      request through without a bearer; every non-anonymous route is
      unaffected.
- [ ] Anonymous writes carry no subject/organisation stamp; the mutual
      exclusion with elevated `minTrust` is enforced fail-closed.
