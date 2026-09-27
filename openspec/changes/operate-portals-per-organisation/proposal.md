---
kind: code
depends_on: [portal-scoping-and-auth]
---

# Proposal: operate-portals-per-organisation

## Why

A hosting party that wants to run portals for twenty municipalities on one
Nextcloud needs two things it cannot get today. It needs proof that one
municipality's residents never see another's data, for every kind of record
the portal holds. And it needs a way to add the next municipality without
editing objects by hand.

Portaliq matrix, row `ops-multitenancy-organisation`, "Scope a portal
session and its data to one organisation.", rated `partial`, `built.state`
`built`. Its `built.note`, verbatim:

> Rated partial rather than yes: this reader confirmed the session claim and the branding-isolation guard, but did not trace a full object-store query to prove row-level data isolation end to end for every schema; that needs a live check across the 13 schemas in lib/Settings/portaliq_register.json.

Two competitors are rated `yes`. `xxllnc-pip`, verbatim:

> backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:59 per-instance session; backend/perl-api/etc/customer.d/ [reached on every route]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/security-and-administration/users-and-permissions/organizations 'Organizations ... can enable distributed user and site management'; object data can be restricted per account (https://learn.liferay.com/w/dxp/low-code/objects/creating-and-managing-objects/using-system-objects-with-custom-objects/restricting-access-to-object-data-by-account).

Portaliq matrix, row `cmp-ops-multitenant`, "Run portals for several
organisations on one installation, each with its own data.", rated
`partial`, `built.state` `built`. Its `built.note`, verbatim:

> Same caveat as ops-multitenancy-organisation: the session-level and branding-level isolation is confirmed, full row-level data isolation across every schema was not traced end to end in this pass.

Three competitors are rated `yes`. `xxllnc-pip`, verbatim:

> backend/perl-api/etc/customer.d/ per-hostname instances; backend/zaken/src/zsnl_style_http/routes/routes.py:31 style tenants [reached on hosting; was partial from docs]

`mijnoverheid`, verbatim:

> kept from the morning docs pass of 2026-09-26; today's docs silent: MijnOverheid itself is one installation serving every connected government organisation, each with its own case/message data reaching only the right resident (logius.nl koppelvlakken)

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/security-and-administration/administration/configuring-liferay/virtual-instances 'Liferay DXP can host multiple "portals" on one installation ... Each virtual instance has completely separate data and configurations'. [since 2026.Q1 multiple domains need an Enterprise subscription]

Portaliq matrix, row `cmp-ops-saas`, "Get the portal as a hosted service
rather than running it yourself.", rated `no`, `built.state` `none`. Its
`built.note`, verbatim:

> A hosting company could offer managed Nextcloud+portaliq as a service, but that would be an external arrangement, not a capability this codebase provides.

Two competitors are rated `yes`. `xxllnc-pip`, verbatim:

> helm/ charts; backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/instances.tt:9 'Omgevingen' control panel for hosted environments [reached on hosting]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/cloud 'Liferay SaaS ... Updating, patching, and managing Liferay DXP and the other core Liferay Cloud services is part of the package'.

The lane recorded all three as `build` on two or more competitors rated
`yes`.

## What changes

- **Every schema says how it is kept apart.** Each of the 48 schemas in
  `lib/Settings/portaliq_register.json` is declared in one place as scoped
  by organisation, by portal, by subject, through its parent, or global. A
  test fails when a schema is added without a declaration. Today 23 of them
  carry neither `organisation` nor `portal`, and 19 carry no `subjectRef`
  either.
- **A missing tenant value refuses, it does not pass.**
  `PortalObjectReader::organisationMatches()` lets a row through when either
  side has no organisation. For a schema declared organisation-scoped, a row
  or a subject without one is dropped.
- **An isolation proof on one installation.** Two organisations, two portals,
  two hosts, the same kinds of data on each side. A suite walks every read
  and write route the portal exposes and asserts that nothing crosses.
- **Add an organisation's portal in one step.** An administrator, or a
  hosting party through `occ portaliq:portal:provision`, creates the portal
  for a new organisation: the `portal` object, its host, its default menu
  and pages, and its authentication set to `public` until configured. It
  refuses a slug or host that another organisation already holds.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `ops-multitenancy-organisation` | Scope a portal session and its data to one organisation. | partial | A declared tenant scope for every schema, a fail-closed organisation check, and an end-to-end proof. |
| portaliq | `cmp-ops-multitenant` | Run portals for several organisations on one installation, each with its own data. | partial | The same proof, across two portals on two hosts on one installation. |
| portaliq | `cmp-ops-saas` | Get the portal as a hosted service rather than running it yourself. | no | A provisioning step a hosting party can script for each new organisation. |

## Existing work it builds on

- `portal-scoping-and-auth` (open): host-based portal resolution with no
  fallback, verified custom domains, per-portal authentication and
  portal-scoped sessions. `lib/Service/PortalResolver.php:118-138` already
  resolves by host. This change does not redo any of it; it proves what that
  change scopes and adds the step that creates a portal.
- `portal-white-label-runtime-config` (open) and
  `lib/Service/PortalOrganisationConfigService.php`: the per-organisation
  branding that fails closed to a neutral default.
- `portal-page-provisioning` (spec) and `landing-page-provisioning` (spec):
  pages as data, which the provisioning step seeds.
- openregister `saas-multi-tenant` (open): the organisation itself, tenant
  provisioning in OpenRegister, OTAP and quotas.

## Out of scope

- Running the hosting. Contracts, infrastructure, backups and billing are
  the hosting party's.
- A separate database per tenant. Openregister's `saas-multi-tenant` decides
  the isolation model at the store; this change proves the portal's layer.
- Nextcloud user accounts for staff of each organisation.

## Sibling halves

- **ConductionNL/openregister** owes the organisation a portal is created
  for, through `saas-multi-tenant`'s "Tenant provisioning API". The
  provisioning step calls it when present and otherwise takes an existing
  organisation identifier. Not written here.
