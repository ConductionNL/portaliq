# Proposal: supplier-portal

## Summary

Build the **supplier portal** as Portaliq's first slice and reference
implementation: the external eHerkenning→JWT auth edge, the white-label React
SPA shell served by Nextcloud, the unified inbox, and the portal-contribution
registry — with **procest** registering the first contribution (its supplier
tenders / contracts / invoices / profile / messages). This proves the whole
ADR-046 pattern end-to-end with the most API-ready audience (procest's supplier
API is already JWT-bearer, tenant-aware, and fail-closed).

## Why supplier first

- procest's `/api/leverancier-portaal/*` is already bearer-shaped, tenant-claim
  aware, and IDOR-safe by `supplierRef` — only the eHerkenning→JWT **edge** is
  deferred ("chain member 02"). Lowest-risk proof of the pattern.
- eHerkenning (company auth) is simpler to stand up than DigiD (citizen auth),
  which the client slice needs.

## Scope

- Portaliq: app skeleton (done), the eHerkenning→JWT auth edge + `portal_session`
  register, the React shell served via `#[PublicPage]`, the contribution-registry
  consumer, the inbox reader over OR's notification engine, and per-Organisation
  runtime config (white-label).
- procest: expose a `PortalContributionProvider` for the supplier audience;
  bearer-guard the `/api/leverancier-portaal/*` reads; retire the in-app supplier
  Vue views once the portal renders them.

## Out of scope (later slices)

- Client/citizen (DigiD) mode and the "Mijn gemeente" string cleanup.
- A second (non-procest) contributor.
- pipelinq customer-portal migration.
- Standalone (off-Nextcloud) container/CF deployment.

## Depends on

- **OpenRegister**: objects API + RBAC + Organisation + notification engine.
- **OpenConnector** (preferred) for the eHerkenning SAML/OIDC exchange.
- procest's existing supplier API + dormant `EHerkenningSamlAdapterInterface` +
  `TenantJwtService`.
