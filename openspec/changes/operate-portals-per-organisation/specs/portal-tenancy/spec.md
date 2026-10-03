---
status: proposed
---

# Spec: portal-tenancy

## Purpose

One installation runs portals for several organisations, each with its own
data, and a hosting party can add the next one in one step. Portaliq matrix
rows `ops-multitenancy-organisation`, `cmp-ops-multitenant` and
`cmp-ops-saas`.

## ADDED Requirements

### Requirement: Every schema declares how it is kept apart (REQ-OPO-001)

Portaliq SHALL declare, for every schema in its register, whether it is
scoped by organisation, by portal, by subject, through a parent, or global
with a stated reason. A test SHALL fail for a schema without a declaration,
and for a schema declared by organisation or portal that lacks that field.

#### Scenario: A developer adds a schema without a decision
- **GIVEN** a new schema in `lib/Settings/portaliq_register.json` with no entry in the tenancy map
- **WHEN** the unit suite runs
- **THEN** `SchemaTenancyCensusTest` fails naming the schema
- @e2e exclude Build-time census; pinned by SchemaTenancyCensusTest

### Requirement: A missing tenant value refuses the row (REQ-OPO-002)

For a schema declared organisation-scoped, the portal reader SHALL drop a
row without an organisation and SHALL return nothing to a subject without
one. For a schema declared portal-scoped, the same SHALL hold for the
resolved portal.

#### Scenario: A row without an organisation stays hidden
- **GIVEN** a `portalMessage` row with the subject's `subjectRef` and no `organisation`
- **WHEN** the subject of `org-a` reads their inbox
- **THEN** the row is not returned
- @e2e exclude Reader branch; pinned by PortalObjectReaderTest::testRowWithoutOrganisationIsDropped

### Requirement: No route crosses from one organisation to another (REQ-OPO-003)

On one installation with two organisations and a portal each, no portal
route and no public site content route SHALL return a record seeded for the
other organisation, whether the caller is signed in or anonymous.

#### Scenario: A resident of one municipality sees only their own
- **GIVEN** portals for `org-a` and `org-b` on one installation, each with a resident who has messages, cases and a submission
- **WHEN** the `org-a` resident opens every page of their portal and every portal API route is called with their session
- **THEN** no response contains an id seeded for `org-b`
- e2e: `tests/e2e/operate-portals-per-organisation.spec.ts`

#### Scenario: An anonymous visitor sees only that portal's content
- **GIVEN** the same two portals, each with published pages and news
- **WHEN** an anonymous visitor browses the `org-a` site
- **THEN** no page, menu entry or news item of `org-b` appears
- e2e: `tests/e2e/operate-portals-per-organisation.spec.ts`

### Requirement: A hosting party can add an organisation's portal in one step (REQ-OPO-004)

`occ portaliq:portal:provision` and a "New portal" action on the Portals
admin page SHALL create a draft portal for an organisation with its host,
a menu and a home page, with authentication `public`, and SHALL return the
DNS record that verifies the host. They SHALL refuse a slug or host another
portal already holds, and SHALL NOT publish the portal.

#### Scenario: A hoster adds a new municipality
- **GIVEN** an installation that serves `org-a`
- **WHEN** an administrator runs `occ portaliq:portal:provision` for organisation `org-b`, slug `zeist`, title "Gemeente Zeist" and host `mijn.zeist.example`
- **THEN** a draft portal for `org-b` exists with a home page and a menu, and the command prints the DNS record to publish
- @e2e exclude CLI; pinned by PortalProvisioningServiceTest and a Newman call on the admin route

#### Scenario: A host already in use is refused
- **GIVEN** `mijn.zeist.example` bound to a portal of `org-b`
- **WHEN** an administrator provisions a portal for `org-c` with the same host
- **THEN** the request is refused with "This web address is already used by another portal." and nothing is written
- e2e: `tests/e2e/operate-portals-per-organisation.spec.ts`
