---
status: proposed
---

# Spec: portal-page-provisioning

## ADDED Requirements

### Requirement: An app MUST be able to provision a portal page as data

An app (or an admin) SHALL be able to provision a portal contribution by
creating an OpenRegister object of schema `portalPage` (register `portaliq`)
instead of shipping a PHP `PortalContributionProvider` class. Portaliq's
built-in provider (`OCA\Portaliq\Portal\PortalContributionProvider`) SHALL
read ACTIVE (`status: active`) `portalPage` objects and surface them through
the same aggregation and normalisation path (`PortalContributionRegistry`,
`PortalManifestNormaliser`) every PHP-provided contribution already flows
through. A `draft` `portalPage` object SHALL NOT appear in any aggregate.

#### Scenario: An app provisions a citizen-facing page with zero Portaliq code

- **GIVEN** OpenBuild creates a `portalPage` object with `audience:
  "citizen"`, `status: "active"`, one `collections` entry, one `actions`
  entry, and one `pages` entry composing them
- **WHEN** a subject authenticated for audience `citizen` requests their
  portal manifest (`GET /portal/api/contributions` / `index()`)
- **THEN** the response includes that contribution, rendered exactly as it
  would be had OpenBuild instead shipped a PHP provider class

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
identity provider to be configured or live — it is available as soon as a
manifest entry opts in.

#### Scenario: An anonymous citizen submits a public intake form

- **GIVEN** a `portalPage` action `{id: "meldenMaken", type: "create",
  register: "openbuild", schema: "melding", fields: ["title",
  "description"], anonymous: true}`
- **WHEN** an unauthenticated caller (no `Authorization` header at all)
  `POST`s `{title: "...", description: "..."}` to `/portal/api/collections/
  openbuild/melding`
- **THEN** the request succeeds (not 401), only the whitelisted fields are
  written, and the created object carries no subjectRef/organisation stamp

#### Scenario: A non-anonymous action still requires a bearer session

- **GIVEN** an action WITHOUT `anonymous: true`
- **WHEN** an unauthenticated caller `POST`s to the same collection route
- **THEN** the request is rejected 401, unchanged from today's behaviour

#### Scenario: An anonymous visitor can read the page layout before submitting

- **GIVEN** a `portalPage` with at least one `anonymous: true` entry
- **WHEN** an unauthenticated caller requests the portal manifest
- **THEN** the response contains ONLY that contribution's anonymous-flagged
  collections/actions/pages (not any private sibling entry in the same
  contribution, and not any other audience's private contribution), instead
  of a 401

### Requirement: Anonymous and elevated trust MUST NOT combine on one entry

Portaliq SHALL fail closed when one entry declares both `anonymous: true`
and a `minTrust` above `low` — a contradiction, since there is no subject to
hold a trust level on an anonymous call — by dropping `anonymous` on that
entry, so it falls back to requiring an authenticated, trust-checked bearer,
never the reverse.

#### Scenario: A malformed manifest entry cannot widen access

- **GIVEN** a `portalPage` action `{anonymous: true, minTrust:
  "substantial"}`
- **WHEN** the manifest is normalised
- **THEN** the surfaced entry no longer carries `anonymous: true`, and an
  unauthenticated caller cannot invoke it — only a bearer-authenticated
  subject with `substantial`+ trust can

### Requirement: Trust-elevated (non-anonymous) pages are unaffected

Elevated-trust, non-anonymous entries SHALL continue to require a resolved
bearer subject whose session trust (from `portalAccount`/`portalSession`,
minted via whatever `identityType` issued it) satisfies the declared
`minTrust`, exactly as contract v2 already specifies — unchanged by this
change. DigiD/eHerkenning/eIDAS remain OPTIONAL trust elevation for pages
that opt into a higher `minTrust` — never a precondition for the anonymous
or `low`-trust surface this change adds.

#### Scenario: An elevated-trust action still gates on session trust, not on identity provider presence

- **GIVEN** a `portalPage` action with `minTrust: "substantial"` and no
  `anonymous` flag
- **WHEN** a bearer-authenticated subject whose session trust normalises to
  `low` invokes it
- **THEN** the request is rejected 403, identical to today's `minTrust`
  enforcement — unaffected by this change

## Notes

- **@e2e**: covers the anonymous-submission scenario end-to-end once a
  `portalPage`-provisioned demo page ships (task 1.4); the 403/401 regression
  scenarios are covered by `ContributionControllerTest` /
  `PortalAuthMiddlewareTest` (tasks 6.3-6.4).
- Builds on `portal-contribution-contract` (canonical spec,
  `openspec/specs/portal-contribution-contract/spec.md`) and
  `contribution-manifest-v3` — does not modify either's existing
  requirements, only adds the data-provisioning source and the anonymous
  mode.
