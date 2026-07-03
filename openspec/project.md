# Portaliq — OpenSpec Project

Portaliq is the fleet's **shared external portal** for the two non-Nextcloud
audiences: **clients/citizens** and **suppliers**. It is a thin hub on top of
OpenRegister (ADR-046): it owns the external auth edge, the white-label React
SPA, the unified inbox, and the **portal-contribution registry**. Domain apps
register a contribution; domain data lives in OpenRegister.

## Boundaries

- **Portaliq owns:** the auth edge (DigiD/eHerkenning/eIDAS → subject + bearer
  session), the SPA shell, the inbox reader, the contribution registry + admin.
- **Portaliq does NOT own:** domain business logic (stays in the domain apps) or
  data storage (OpenRegister). No app-to-app calls — aggregation is via OR.
- **Out of scope:** employees / internal Nextcloud users (they keep the NC shell
  + the integration registry, ADR-019).

## Canonical references

- **ADR-046** (hydra) — Portaliq: one shared external portal, apps hook in.
- **ADR-019** — integration registry (the contribution registry extends it).
- **ADR-022** — apps consume OpenRegister abstractions.
- **ADR-005** — security (separate auth domain, server-derived scope, fail-closed).
- `specs/tenant-fleet-wide-consumption` (hydra) — tenant = OR Organisation.
- `fleet-notification-plan.md` (hydra) — the OR notification engine the inbox reads.

## Changes

- `supplier-portal` — the first slice + reference implementation (procest is the
  first contributor).
