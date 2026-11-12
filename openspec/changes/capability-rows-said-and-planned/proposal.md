---
kind: spec
---

# Proposal: capability-rows-said-and-planned

## Why

The closing round of 12 November 2026 re-read every capability row whose requirement was linked since 11 November. For most rows the linked scenarios say the row. For sixteen rows they say only part of it, and no other requirement says the rest. Four rows marked built do not work end to end, because the half that would call portaliq lives in another app and was never built. Two rows that are not built had no open change.

## What changes

Sixteen requirements are added, each with at least one scenario, to the main spec nearest the row:

- **portal-account-administration:** staff withdraw an account issued by mistake.
- **portal-profile:** a resident changes their own name.
- **portal-contribution-contract:** an app joins the portal by convention alone; a contributed page is built from typed blocks; a contribution says how its data reads; an anonymous visitor reads public contributed content.
- **portal-federated-search:** a search from the home page opens the results; results can be sorted by date.
- **portal-traffic-reporting:** the CSV export follows what the page shows; a portal switches measurement off.
- **portal-traffic-visitors-and-geo:** a returning visitor is known as the same visitor.
- **first-time-setup:** an administrator walks through the setup wizard.
- **supplier-portal:** staff actions on portal accounts and sessions are in the trail.
- **site-mijn-omgeving:** the overview shows running cases and the newest messages.
- **site-shared-dossier:** a shared dossier shows only what is public now.
- **portaliq-cms:** the public content API answers other sites.

The tasks also plan the work that is still owed for the rows that are not built (see Rows covered).

No code changes in this change. Most of the requirements describe behaviour the row evidence already names in code; task T02 checks each against that code. Sorting by date (REQ-RSP-008) is not built.

## Rows covered

Said in full: `id-staff-void`, `id-update-own-details`, `con-registry-discovery`, `con-contributed-blocks`, `con-ui-config-v3`, `con-anonymous-aggregation`, `srch-hero-quick-search`, `sib-opencatalogi-srch-sort`, `ana-export-csv`, `ana-per-portal-config`, `cmp-ana-profiles`, `ops-setup-wizard`, `ops-audit-trail`, `cmp-ops-audit`, `cas-overview-dashboard`, `cas-shared-dossier-link`, `ops-cors-public-api`.

Planned here, not built: `tsk-ask-partner`, `sib-dossiq-3-19`, `dem-tnd-joint-case-partner`, `dem-tnd-sign-in-portal`, `cmp-act-ask-question-case`, `sib-opencatalogi-srch-sort`.
