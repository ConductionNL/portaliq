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
- [x] **T05**: Read declared collections via OpenRegister's objects API scoped by subject; render list. — DONE + LIVE-VERIFIED against OR 0.2.17 in the running NC34 container. `PortalObjectReader` calls `setRegister()`/`setSchema()` then `findAll(config, _rbac: false, _multitenancy: false)` filtered by the declared `scopeField` (= subjectRef), re-verifies every row (drops foreign-subject + foreign-org rows), degrades to `[]` without OR. Guarded endpoint `GET /portal/api/collections/{register}/{schema}` authorises (register,schema) against the subject's own manifest first. React renders a per-collection loader. Proven live: dev-supplier sees only its doc, other-supplier sees only its own (cross-subject isolation). Four bugs the live probe caught + fixed (see design.md "Live-hardening"): (1) JWT DI factory didn't resolve → PortalSessionService builds PortalJwtService from IConfig; (2) request-scoped context not shared middleware↔controller → controller self-resolves, middleware is gate-only; (3) `registerServiceAlias` isn't cross-app → discover providers by convention FQCN `OCA\{Ns}\Portal\PortalContributionProvider`; (4) portal subjects aren't NC users so OR RBAC/multitenancy filtered everything → call findAll with both OFF (Portaliq is the trusted scoping intermediary). Unit-tested + live-tested.
- [~] **T06**: Render declared `actions` as buttons. — DONE for `type: create` actions (OR writes), LIVE-VERIFIED. `PortalObjectWriter` calls `ObjectService::saveObject(..., _rbac: false, _multitenancy: false)` and STAMPS the subjectRef + tenant server-side (overriding any client value). `ContributionController::create()` (`POST /portal/api/collections/{register}/{schema}`) authorises against a declared `create` action in the subject's OWN manifest (403 otherwise) and whitelists to the action's declared `fields`. React renders create buttons. Proven live: created a doc as dev-supplier with a smuggled `subjectRef:HACKER` → stored as `dev-supplier`; create in a non-granted schema → 403. Unit-tested (ownership stamp beats client input; RBAC off; graceful degrade). REMAINING variant: `endpoint`-style actions that bearer-forward to a domain app's own endpoint (for non-OR domain actions like procest request-renewal) — future.

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
