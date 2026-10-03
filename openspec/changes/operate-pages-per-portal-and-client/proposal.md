---
kind: code
depends_on: []
---

# Proposal: operate-pages-per-portal-and-client

> Retargeted 2026-10-01 (`site-reaches-portal-parity`): new frontend work in this change lands in the Vue site `src/site/`, not in the React portal `src/portal/`, which is being retired.

## Why

Every contribution a subject's audience may see lands in the portal's
navigation, in the order the apps happen to be installed. An organisation
cannot leave the quotes page out of its resident portal, and a clerk cannot
take the invoices page away from one business client whose invoices go
through an accountant. The only filters are the audience and the trust level
(`PortalContributionRegistry::aggregateFor()`).

The request comes from pipelinq. Pipelinq matrix row `portal-menu-choice`,
"Choose which menu items and records a client sees in their portal", pipelinq
rated `partial`, now `decided-no` in pipelinq's matrix with
`built.owner` `ConductionNL/portaliq`. Its `built.evidence` describes what
pipelinq's own portal did:

> each portal tenant carries enabledFeatures (lib/Service/Portal/PortalTenantService.php:62 PUBLIC_FIELDS, isFeatureEnabled) and src/portal/views/PortalDashboard.vue:123 "all.filter((tab) => this.features.includes(tab.key))" shows only those tabs [...] The choice is per tenant, not per client

Its note, verbatim: "Decided no 2026-09-28 (OpenSpec pass): The missing
half, a menu and record choice per client, belongs to the shared portal
(hydra ADR-046, portaliq menus site-menus-layout); the bespoke pipelinq portal
that holds today's per tenant choice is due to retire (open change
portal-contribution). Owner corrected to ConductionNL/portaliq."

Demand: origin `featureRequest`, <https://github.com/odoo/odoo/issues/192439>.
One competitor is rated `yes`. `espocrm`, verbatim:

> Each portal has its own tab list and quick create list (application/Espo/Resources/metadata/entityDefs/Portal.json:35 "tabList", :47 "quickCreateList"), and portal roles set which records a client sees at levels all, account, contact or own (Crm scopes/Case.json:7 "aclPortalLevelList")

`hubspot-crm` and `odoo-crm` are rated `partial`; `kiss` `no`.

Decision `build` in the owner-moves pass of 2026-09-28: a feature request
plus one competitor rated `yes`. The row has no portaliq matrix row; it is
carried by pipelinq's matrix. When pipelinq's bespoke portal retires, this is
where its per tenant choice lands.

## What changes

- **Per portal: which pages are in the navigation, and in which order.** An
  administrator opens a portal in the portaliq admin and sees every page the
  installed apps contribute, per audience. Each can be shown or hidden and
  moved up or down. A hidden page is gone from that portal's navigation.
- **Per client: which pages and records one account sees.** A clerk opens a
  portal account and hides pages for that account alone. A page hidden for an
  account also closes the collections only that page shows, so the account
  cannot read those records through the API either.
- **Nothing changes until someone chooses.** A portal with no choice and an
  account with no choice see exactly what they see today.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| pipelinq | `portal-menu-choice` | Choose which menu items and records a client sees in their portal | partial (pipelinq) | A page choice per portal and a page and record choice per client in the shared portal. |

## Existing work it builds on

- `portal-contribution-contract` (spec): contributions, their `pages` and
  `collections`, and the audience and trust filters.
- `operate-portals-per-organisation` (open change): one organisation owning
  several portals.
- `identity-staff-account-screens` (open change): the staff screens on a
  portal account, where the per client choice is set.

## Out of scope

- Record rules below the collection, such as "only open invoices". A
  contribution declares its collections and scopes; portaliq does not filter
  inside them.
- Renaming a contributed page per portal.
- Groups of clients sharing one choice. A choice is per portal or per
  account.

## Sibling halves

- **ConductionNL/pipelinq**: its open change `portal-contribution` retires
  the per tenant `enabledFeatures` choice; an administrator re-creates it
  once, as a per portal choice here. Nothing in pipelinq needs to change for
  this change to work.
