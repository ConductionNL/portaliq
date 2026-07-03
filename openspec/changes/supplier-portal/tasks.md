# Tasks: supplier-portal

> First Portaliq slice + reference implementation. Portaliq app skeleton is in
> place (info.xml, Application, PortalPageController `#[PublicPage]`, routes,
> React shell). These tasks build the supplier slice end-to-end.

## Deduplication / Dependency Check

- [ ] **DC01**: Confirm OpenRegister exposes the anonymous+authenticated objects API, RBAC (schema `public` group + predicates), the Organisation entity, case-tokens, and the `x-openregister-notifications` engine in the deployed OR version; record the min OR version in `appinfo/info.xml`.
- [ ] **DC02**: Confirm the eHerkenning broker path (OpenConnector) is available and can return an EH3 assertion with KvK number; if not, file the gap on OpenConnector rather than embedding a SAML lib in Portaliq.
- [ ] **DC03**: Confirm procest's `/api/leverancier-portaal/*` reads are already `supplierRef`-scoped + IDOR-safe (they are) and reusable behind a bearer guard; do NOT reimplement supplier CRUD in Portaliq.

## Portaliq — auth edge

- [ ] **T01**: Add the `portal_account` + `portal_session` schemas as a Portaliq-owned OpenRegister register (`lib/Settings/portaliq_register.json`).
- [ ] **T02**: Wire the eHerkenning→JWT edge: `SessionController` (`POST /portal/api/session` start, `GET /portal/api/session` resolve, `DELETE` logout) + `PortalAuthMiddleware` (bearer validation → `_supplierRef`, fail-closed 401). Reuse procest's `EHerkenningSamlAdapterInterface` / `SupplierAuthService` / `TenantJwtService` patterns via OpenConnector.
- [ ] **T03**: Enforce trust level (EH3) and tenant claim on session mint; never trust a client-supplied scope.

## Portaliq — contribution registry + rendering

- [ ] **T04**: Define the `PortalContributionProvider` contract (PHP interface + OCS capability discovery, ADR-019 parity) and the `PortalContributionRegistry` consumer that aggregates registered contributions per authenticated subject.
- [ ] **T05**: Read declared collections via OpenRegister's objects API RBAC-scoped by `supplierRef` + Organisation; render list + detail with NL-DS components in the React shell.
- [ ] **T06**: Render declared `actions` as buttons that call the app's endpoints (bearer-forwarded).

## Portaliq — inbox + white-label

- [ ] **T07**: Inbox reader over OR `x-openregister-notifications` for `supplierRef` recipients; one aggregated list in the shell. Track the OR external-recipient/relation-resolver dependency.
- [ ] **T08**: Resolve `window.RUNTIME_CONFIG` (theme/logo/name/IdP/flags) server-side in `PortalPageController::index()` from the tenant's OR Organisation record; theme the React shell (NL-DS variants).

## procest — first contributor

- [ ] **T09**: Add procest's `PortalContributionProvider` for the supplier audience (collections: supplierTender/Contract/Invoice; actions: requestRenewal, submitAccreditation, updateProfile; notifications).
- [ ] **T10**: Bearer-guard `/api/leverancier-portaal/*` reads (flip from `#[NoAdminRequired]`; the `SupplierAuthMiddleware` already exists) so Portaliq can call them for a portal subject.
- [ ] **T11**: Retire procest's in-app supplier surface (`src/views/leverancier/*`, `manifest.d/60-leverancier.json`) once Portaliq renders the contribution; leave the API + facades.

## Frontend build + tests

- [ ] **T12**: Build the React portal (`npm run build` → `js/portaliq-portal.js`); add the eHerkenning login handshake, session store, contribution renderer, and inbox view.
- [ ] **T13**: Tests — PHP unit for the auth edge + registry (fail-closed, IDOR, tenant isolation); a Playwright e2e for the supplier login → see tenders/contracts/invoices → perform an action → receive a notification.

## Gates / review

- [ ] **T14**: Security review of the eHerkenning edge before merge (ADR-005: separate auth domain, server-derived scope, fail-closed, no PII in logs). Hydra gates green.
