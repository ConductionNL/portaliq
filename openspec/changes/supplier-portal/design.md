# Design: supplier-portal

> Design for the first Portaliq slice. See ADR-046 and the fleet design brief
> (`hydra/openspec/changes/portaliq-external-portal/design.md`) for the overall
> shape; this narrows to the supplier audience.

## 1. Auth edge (eHerkenning → bearer session)

- **Flow:** browser → Portaliq `/portal` shell → "Log in with eHerkenning" →
  OpenConnector-brokered eHerkenning SAML/OIDC → assertion (with KvK number +
  trust level EH3) → Portaliq mints a **bearer session** (`TenantJwtService`,
  short TTL + refresh) carrying `{ supplierRef, supplierUserId?, supplierRole?,
  organisation, trustLevel }`.
- **`portal_session` register** (Portaliq-owned OR register) records the issued
  session; **`portal_account`** links the eHerkenning identity to a supplier.
- **Fail closed:** `/portal/api/session` returns 401 without a valid session; a
  `PortalAuthMiddleware` validates the bearer on every `/portal/api/*` request
  and injects `_supplierRef` (mirrors procest's `SupplierAuthMiddleware`).
- **Reuse:** procest already has `EHerkenningSamlAdapterInterface`,
  `SupplierAuthService`, `SupplierScopeService`, `TenantJwtService` — Portaliq
  owns the live wiring so it exists once for the fleet.

## 2. Contribution registry (procest supplier contribution)

procest exposes a `PortalContributionProvider` (ADR-019-style discovery) declaring:

```jsonc
{
  "app": "procest", "audience": "supplier",
  "identity": { "match": "supplierRef == $subject" },
  "collections": [
    { "register": "procest", "schema": "supplierTender",   "list": {...}, "detail": {...} },
    { "register": "procest", "schema": "supplierContract", "list": {...}, "detail": {...} },
    { "register": "procest", "schema": "supplierInvoice",  "list": {...}, "detail": {...} }
  ],
  "actions": [
    { "id": "requestRenewal",      "on": "supplierContract",
      "endpoint": "/api/leverancier-portaal/contracts/{id}/request-renewal" },
    { "id": "submitAccreditation", "endpoint": "/api/leverancier-portaal/profile/accreditations" }
  ],
  "notifications": ["tenderPublished", "contractExpiring", "invoiceDue"]
}
```

- **Read path:** Portaliq lists/reads the declared collections via **OpenRegister's
  objects API**, RBAC-scoped by `supplierRef` + Organisation — not via procest
  (ADR-022: OR exposes data, apps expose actions).
- **Action path:** action buttons call the app's declared endpoints (bearer-
  guarded on the procest side).

## 3. React shell (served by Nextcloud)

- Skeleton is in place (`templates/portal.php` + `src/portal`). This slice adds:
  the eHerkenning login handshake, the session store, the contribution renderer
  (list + detail using NL-DS components), and the inbox view.
- **White-label:** `window.RUNTIME_CONFIG` (theme/logo/name/IdP) resolved
  server-side in `PortalPageController::index()` from the tenant's OR Organisation.

## 4. Inbox

- Reads OR's `x-openregister-notifications` for the supplier's `supplierRef`
  recipients; renders one list. Requires the OR engine's **external-recipient /
  relation resolver** for supplier-user emails (flagged in
  `hydra/openspec/fleet-notification-plan.md`) — track as an OR dependency.

## 5. procest migration

- Add the `PortalContributionProvider`; flip `/api/leverancier-portaal/*` reads
  from `#[NoAdminRequired]` to bearer-guarded; retire `src/views/leverancier/*`
  and the `60-leverancier.json` in-app portal pages once Portaliq renders them.

## Security (ADR-005)

Separate auth domain; server-derived `supplierRef` only (never a client param);
fail-closed session resolvers; per-object IDOR on every read/action; tenant
isolation at the service layer; no KvK/PII in logs. The eHerkenning edge is the
highest-risk new surface → security-review gate before merge.

## Open questions

- eHerkenning broker: OpenConnector vs a direct SAML lib (leaning OpenConnector).
- Contribution discovery transport: OCS capability (ADR-019 parity) vs OR-registered list.
- Inbox external-email: fix in the OR engine (preferred, all apps benefit) vs Portaliq-local.
