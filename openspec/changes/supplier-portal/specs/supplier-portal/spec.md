---
status: proposed
---

# Spec: supplier-portal

**Status:** proposed
**Scope:** portaliq (owner) + procest (first contributor)
**Depends on:** OpenRegister (objects API + RBAC + Organisation + notification engine); OpenConnector (eHerkenning broker); procest supplier API + dormant `EHerkenningSamlAdapterInterface` / `TenantJwtService`
**Standard:** eHerkenning (EH3); ADR-046; ADR-005 (security); ADR-019 (integration registry); ADR-022 (consume OR)

## Purpose

Deliver Portaliq's supplier portal as the reference implementation of ADR-046: a
supplier authenticates via eHerkenning, receives a bearer session, and sees + acts
on their records — sourced from procest's registered *portal contribution* and
read through OpenRegister — inside the shared white-label portal, with one inbox.
Portaliq owns the auth edge, shell, inbox, and contribution registry; procest owns
its supplier data (in OR) and its client-facing actions. Neither owns the other's
concern.

## ADDED Requirements

### Requirement: Portaliq MUST authenticate suppliers via a separate bearer auth domain

Portaliq SHALL authenticate a supplier through eHerkenning (EH3) and mint a bearer
session carrying a server-derived `supplierRef`, `organisation`, and trust level;
it SHALL NOT rely on a Nextcloud session, and SHALL NOT trust any client-supplied
scope. Every `/portal/api/*` request SHALL be validated by a middleware that
fails closed (401) and injects the server-derived `supplierRef`.

#### Scenario: Supplier logs in with eHerkenning
- **GIVEN** an unauthenticated visitor on the Portaliq shell for an Organisation
- **WHEN** they complete the eHerkenning (EH3) flow brokered by OpenConnector
- **THEN** Portaliq mints a bearer session with `supplierRef` + `organisation` + trust level derived from the assertion
- **AND** subsequent `/portal/api/*` calls carrying the bearer resolve that `supplierRef` server-side

#### Scenario: Missing or invalid session fails closed
- **GIVEN** a request to `/portal/api/*` with no bearer, an expired bearer, or a bearer below the required trust level
- **WHEN** the middleware evaluates it
- **THEN** the request is rejected with 401 and no data is returned

### Requirement: An app MUST be able to register a supplier portal contribution

Portaliq SHALL discover, at runtime, portal contributions declared by domain apps
(ADR-019-style) and aggregate them for the authenticated subject. A contribution
declares its audience, identity match, readable collections (OR register+schema),
client-facing actions, and notification rule keys. Portaliq SHALL render the
declared collections and actions without any app-specific code.

#### Scenario: procest registers its supplier contribution
- **GIVEN** procest exposes a `PortalContributionProvider` for audience `supplier` declaring collections `supplierTender` / `supplierContract` / `supplierInvoice`, actions `requestRenewal` / `submitAccreditation`, and notifications
- **WHEN** Portaliq resolves contributions for an authenticated supplier
- **THEN** the supplier sees their tenders, contracts, and invoices, and the declared actions — with no procest-specific code in Portaliq

### Requirement: Portal reads MUST go through OpenRegister, RBAC-scoped to the subject

Portaliq SHALL list and read a contribution's collections via OpenRegister's
objects API, RBAC-scoped by `supplierRef` + Organisation; it SHALL NOT call the
domain app for *listing* data (ADR-022). Actions (writes) SHALL call the app's
declared, bearer-guarded endpoints.

#### Scenario: A supplier sees only their own records
- **GIVEN** an authenticated supplier with `supplierRef = S1` in Organisation `O1`
- **WHEN** Portaliq reads the `supplierContract` collection via OpenRegister
- **THEN** only contracts where `supplierRef == S1` and `organisation == O1` are returned (RBAC + tenant isolation), and never another supplier's or another tenant's records

### Requirement: The portal MUST be organisation-agnostic and white-label

Portaliq SHALL resolve per-Organisation presentation (name, logo, theme, IdP
config, feature flags) at runtime from the tenant's OpenRegister Organisation
record and expose it as `window.RUNTIME_CONFIG`; the shell SHALL contain no
organisation-specific literals (no "municipality"/"gemeente").

#### Scenario: One build serves two organisations differently
- **GIVEN** two Organisations `O1` and `O2` with different name/logo/theme
- **WHEN** a supplier of each opens the same Portaliq build
- **THEN** each sees their own Organisation's name, logo, and theme, from `RUNTIME_CONFIG`, with no rebuild

### Requirement: The supplier MUST see one aggregated inbox

Portaliq SHALL present a single inbox aggregating notifications for the supplier's
`supplierRef` from every registered contribution, read from OpenRegister's
`x-openregister-notifications` engine (no parallel notification subsystem).

#### Scenario: A contract-expiry notification reaches the supplier inbox
- **GIVEN** procest declares a `contractExpiring` notification and a supplier's contract approaches expiry
- **WHEN** the OR notification engine emits the event with the supplier as recipient
- **THEN** it appears in the supplier's Portaliq inbox

## Notes

- **procest migration:** once Portaliq renders the supplier contribution, procest's
  in-app supplier views (`src/views/leverancier/*`, `manifest.d/60-leverancier.json`)
  are retired; the API + facades stay.
- **@e2e** T13 covers login → collections → action → notification.
