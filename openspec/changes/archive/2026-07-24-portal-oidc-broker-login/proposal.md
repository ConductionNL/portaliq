# Proposal: portal-oidc-broker-login (real external login edge — DigiD/eHerkenning/eIDAS via OIDC)

## Why

This is the flagship gap. Portaliq's whole premise is an account-less portal for
citizens and businesses authenticated by **DigiD / eHerkenning / eIDAS** — and
that login edge does not exist. Agent A: *"external-idp (DigiD/eHerkenning/eIDAS/
OIDC) MISSING — `SessionController.php:10` 'broker dormant, awaiting
OpenConnector'; `PortalOrganisationConfigService.php:165` placeholder; SPA login
buttons disabled. Dev-login only."* The `portalAccount.identityType` enum already
names `eherkenning`/`digid`/`eidas`, the trust vocabulary (`low`/`substantial`/
`high`) is in place, and the openspec text mentions eHerkenning ×43, DigiD ×14,
eIDAS ×2 — the edge is *designed-for but unimplemented*. Everything downstream
(scoping, trust re-checks, receipts, audit) is real; the front door is a stub.

Demand is overwhelming and specific: `digid` in **460 tender requirements across
118 tenders**, `eherkenning` **373 / 107**, `eidas` **259 / 82**. GEMMA's
*Mijngemeentecomponent* lists the DigiD SAML koppelvlak and eHerkenning DV-HM as
baseline constraints. Without a real login edge, portaliq cannot bid.

Agent D found the clean path: *"Signicat Identity Broker = cleanest path:
eHerkenning/DigiD over OIDC or SAML, PKIo certs via Logius; any PHP OIDC client
works. Direct Logius = PKIo+metadata burden; brokers abstract it."* So portaliq
implements a **generic OIDC Relying Party**, broker-agnostic: a Signicat-class
broker exposes DigiD/eHerkenning as plain OIDC, and the PKIo / Logius metadata
burden stays at the broker. Portaliq stays a standard OIDC RP.

## What Changes

- A generic, per-organisation **OIDC Relying Party** in portaliq (in-process PHP,
  NOT an ExApp — the auth edge is deliberately in-process, agent D). Per-org
  broker config: `issuer`, `clientId`, `clientSecret` (stored via app config /
  `IAppConfig`, never rendered), `scopes`, a provider preset
  `digid|eherkenning|eidas|generic`, claim mappings
  (claim → `identityType`/`identityRef`/`subjectRef`/`audience`), and a
  LoA → trust mapping onto the existing `low|substantial|high` vocabulary.
- **`GET /portal/api/session/oidc/start?org=&provider=`** — builds the
  authorization request (state + nonce + PKCE) and 302-redirects to the broker's
  authorization endpoint.
- **`GET /portal/api/session/oidc/callback`** — exchanges the code, validates the
  ID token (issuer, audience, nonce, expiry, RS256 signature via cached JWKS),
  maps claims, finds-or-creates the `portalAccount`, mints the EXISTING HS256
  portal session (`PortalSessionService::issueSession()`), and redirects to the
  SPA. It **fails closed on EVERY validation error** with an identical generic
  error (no oracle about which check failed).
- **Dev-login stays** debug-gated and issues trust `low` — unchanged, for local
  development only.
- **SPA** — enable the per-org login buttons (currently disabled) with provider
  labels driven by the org's configured providers.

## Out of scope

- DigiD **Machtigen** / eHerkenning ketenmachtiging as a first-class delegation
  primitive — a later change (agent E flow 4).
- SAML (this edge is OIDC-only; a SAML broker profile is a later slice).
- Replacing the in-app RP with OpenConnector — see design.md; portaliq MUST NOT
  hard-depend on OpenConnector.

## Dependencies

- Builds on `PortalSessionService` (HS256 session minting, `issueSession()`),
  `portalAccount` (identity fields + trust), and
  `PortalOrganisationConfigService` (per-org config, the `idp` placeholder this
  replaces). The absolute-lifetime cap / refresh from
  `portal-session-hardening-v2` composes naturally with a broker-issued session.

## Note on production readiness

Going live with **DigiD** is gated by the DigiD **Normenkader 3.0** annual
ICT-beveiligingsassessment (RE auditor) **and a DPIA** — an **operational gate,
not a code task** (agent E D6). The code edge can be complete and tested against a
broker's acceptance environment while the assessment/DPIA proceed in parallel;
design.md states this explicitly so it is not mistaken for something this change
delivers.
