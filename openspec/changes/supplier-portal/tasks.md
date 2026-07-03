# Tasks: supplier-portal

> First Portaliq slice + reference implementation. Portaliq app skeleton is in
> place (info.xml, Application, PortalPageController `#[PublicPage]`, routes,
> React shell). These tasks build the supplier slice end-to-end.

## Deduplication / Dependency Check

- [~] **DC01**: Confirm OpenRegister exposes the anonymous+authenticated objects API, RBAC, the Organisation entity, case-tokens, and the `x-openregister-notifications` engine. — PARTIAL: register/schema mechanism (rbac + multitenancy dialect) confirmed via the template's OR integration; live anonymous-API + notification-engine confirmation deferred to the data-read slice (T05/T07).
- [x] **DC02**: Confirm the eHerkenning broker path (OpenConnector). — DONE: procest ships a DORMANT `EHerkenningSamlAdapterInterface` + `LogEHerkenningSamlAdapter` stub (throws until an OpenConnector broker + certs are configured). Portaliq mirrors this — JWT edge live now, real broker deferred behind the same interface.
- [x] **DC03**: Confirm procest's `/api/leverancier-portaal/*` reads are `supplierRef`-scoped + IDOR-safe and reusable. — DONE: confirmed. procest's `SupplierScopeService` / `SupplierAuthMiddleware` / `SupplierSessionService` derive `supplierRef` server-side from a validated HS256 JWT, never a client param.

## Portaliq — auth edge

- [x] **T01**: Add the `portalAccount` + `portalSession` schemas as a Portaliq-owned OpenRegister register (`lib/Settings/portaliq_register.json`). — DONE. Also fixed a template bug: the file was named `app_template_register.json` while `SettingsService` loads `portaliq_register.json` (via `APP_ID`), so the register never loaded — renamed.
- [~] **T02**: Wire the eHerkenning→JWT edge. — PARTIAL (first slice): `PortalJwtService` (self-contained HS256, audience-agnostic, unit-tested fail-closed), `PortalSessionService` (issue + `resolveFromBearer`, fail-closed), `SessionController` (`GET /portal/api/session` resolve, `POST /portal/api/session/dev-login` debug-gated, `DELETE` logout), JWT signing-secret DI factory, routes, React shell wired (bearer + dev-login + logout). DEFERRED to the data-read slice: `PortalAuthMiddleware` (lands with the protected data controllers so it isn't inert), OR-backed `portalSession` persistence + revocation, and the real eHerkenning/DigiD broker (dormant behind the interface until OpenConnector).
- [~] **T03**: Enforce trust level and tenant claim on session mint; never trust a client-supplied scope. — PARTIAL: `subjectRef`/`audience`/`organisation`/`trust` are carried in the signed token and only ever read server-side from the validated bearer (never a client param). EH3 enforcement lands with the real eHerkenning broker.

## Portaliq — contribution registry + rendering

- [x] **T04**: Define the `PortalContributionProvider` contract + the `PortalContributionRegistry` consumer. — DONE: `IPortalContributionProvider` (getAudience + getContribution), `PortalContributionRegistry` (alias-discovery `OCA\Portaliq\Contribution\IPortalContributionProvider::{appId}` mirroring the MCP pattern; audience-filtered aggregation), `ExampleContributionProvider` (demo, remove when real ones land), protected `ContributionController` (`GET /portal/api/contributions`) behind `PortalAuthMiddleware`. Also completes the T02 middleware piece: `PortalAuthMiddleware` + `PortalProtected` marker + `PortalRequestContext` + `PortalUnauthorizedException` (fail-closed 401). Unit-tested (registry aggregation + provider + middleware fail-closed).
- [~] **T05**: Read declared collections via OpenRegister's objects API RBAC-scoped by subject + Organisation; render list/detail. — PARTIAL: the contribution *manifest* (collections + actions a subject may see) is served + rendered in the React shell. Reading the actual *objects* per collection via OR's ObjectService is deferred until the API can be verified against a live OR (DC01) — deliberately not guessed.
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
