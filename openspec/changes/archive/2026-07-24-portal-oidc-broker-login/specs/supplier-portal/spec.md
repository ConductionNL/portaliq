---
status: proposed
---

# Spec: supplier-portal (OIDC broker login edge)

## Purpose

Replaces the dormant IdP stub with a real, generic, broker-agnostic OIDC Relying
Party: a per-organisation authorization-code + PKCE flow that validates the
broker's ID token, maps claims to the existing `portalAccount` identity + trust
model, and mints the existing HS256 portal session — fail-closed on every
validation error. DigiD/eHerkenning/eIDAS are provider presets over the generic
RP. Related: ADR-005 (fail-closed), `PortalSessionService` (session minting),
`portalAccount` (identity + trust), DigiD Normenkader 3.0 (an OPS gate, see
design.md).

## ADDED Requirements

### Requirement: Per-organisation OIDC broker configuration

Each organisation MUST be configurable with an OIDC broker: `issuer`, `clientId`,
`clientSecret`, `scopes`, a provider preset (`digid`|`eherkenning`|`eidas`|
`generic`), claim mappings (claim → `identityType`/`identityRef`/`subjectRef`/
`audience`), and a LoA→trust map onto `low|substantial|high`. The `clientSecret`
MUST be stored via app config and MUST NEVER be returned by any endpoint or
rendered in the SPA. An organisation without a configured provider MUST NOT offer
that login option.

#### Scenario: A configured provider is offered; an unconfigured one is not

- **GIVEN** an organisation configured with an `eherkenning` OIDC provider and no `digid`
- **WHEN** the SPA loads that org's login options
- **THEN** an eHerkenning button is shown and no DigiD button is shown; the client secret is never present in any response
- @e2e exclude the button-rendering assertion is written in `tests/e2e/portal-oidc-broker-login.spec.ts` (happy-path group) but requires a live Organisation with an OIDC broker config wired up first (see the file's header) — not run in the apply pass; the config-shape half (`resolve()`'s `oidcProviders` is secret-free and provider-gated) is covered by `PortalOrganisationConfigServiceTest`

### Requirement: OIDC start builds a state + nonce + PKCE authorization request

`GET /portal/api/session/oidc/start?org=&provider=` MUST resolve the org+provider
config (fail closed if absent), generate `state`, `nonce`, and a PKCE
`code_verifier`/`code_challenge` (S256), persist them server-side single-use and
TTL-bounded keyed by `state`, and 302-redirect to the broker's authorization
endpoint with `state`, `nonce`, and `code_challenge`.

#### Scenario: Start redirects with state, nonce, and PKCE

- **GIVEN** an org with a configured provider
- **WHEN** a visitor hits `/portal/api/session/oidc/start?org=<slug>&provider=<p>`
- **THEN** they are 302-redirected to the broker authorization endpoint carrying `state`, `nonce`, and a S256 `code_challenge`, and the `state`→`{nonce, verifier, org, provider}` is stored single-use
- @e2e exclude authorization-request contract — covered by PHPUnit (redirect params, stored state); external redirect not e2e-drivable in-app

#### Scenario: Start on an unconfigured org/provider fails closed

- **GIVEN** an org/provider with no OIDC config
- **WHEN** start is called
- **THEN** it fails closed with a generic error and issues no redirect
- @e2e tests/e2e/portal-oidc-broker-login.spec.ts

### Requirement: OIDC callback validates the ID token and fails closed on every error

`GET /portal/api/session/oidc/callback` MUST look up the `state` (unknown /
reused / expired → fail closed), exchange the `code` with the stored PKCE
verifier, and validate the ID token with ALL of: `iss` equals the configured
issuer, `aud` contains the `clientId`, `nonce` equals the stored nonce, `exp`/
`iat` within skew, and an **RS256 signature** verified against the broker's
cached JWKS. `alg: none` MUST be rejected. ANY validation failure MUST return an
IDENTICAL generic error and mint NO session — no response distinguishes which
check failed.

#### Scenario: A valid callback mints a portal session

- **GIVEN** a callback with a valid `state`, exchangeable `code`, and an ID token passing all checks (iss, aud, nonce, exp, RS256 signature)
- **WHEN** the callback is processed
- **THEN** claims are mapped, the `portalAccount` is found-or-created, and an HS256 portal session is minted via `PortalSessionService::issueSession()` before redirecting to the SPA
- @e2e exclude the happy-path start→callback round trip against a stub broker is written in `tests/e2e/portal-oidc-broker-login.spec.ts` (skipped unless `OIDC_E2E_LIVE=1` — needs a live Organisation with an OIDC broker config wired up first, see the file's header) — not run in the apply pass; asserted at unit level against a stub broker + JWKS in `SessionControllerTest` and `OidcClientServiceTest`

#### Scenario: Every validation failure is an identical generic error

- **GIVEN** callbacks that each fail exactly one check — reused `state`, wrong `iss`, wrong `aud`, wrong `nonce`, expired token, bad RS256 signature, `alg: none`, or JWKS unavailable
- **WHEN** each is processed
- **THEN** each returns the IDENTICAL generic error, mints no session, and reveals nothing about which check failed
- @e2e exclude the state/CSRF-replay slice (missing state, unknown/never-issued state, broker-reported error) runs live against the API in `tests/e2e/portal-oidc-broker-login.spec.ts`; the iss/aud/nonce/exp/signature/`alg:none` slice needs a broker round trip and is covered by a PHPUnit validation matrix (`OidcClientServiceTest`) instead — no UI surface for those checks

#### Scenario: The subject reference is server-derived, never client-supplied

- **GIVEN** a callback whose token maps to a subject
- **WHEN** the account is found-or-created and the session minted
- **THEN** `subjectRef`/`identityRef` come only from the validated token claims per the org's claim map — a client-supplied subjectRef is never trusted
- @e2e exclude claim-derivation invariant — covered by PHPUnit; no UI surface

### Requirement: Broker LoA maps to portal trust, under-privileging on ambiguity

The broker's LoA / `acr` MUST map to the portal trust vocabulary
(`low|substantial|high`) via the per-org `loaMap`. An unmapped or missing LoA
MUST map to the LOWEST trust (`low`), never the highest. The minted session's
trust MUST be the mapped value, and downstream `minTrust` re-checks continue to
gate collections and actions unchanged.

#### Scenario: A recognised LoA maps to its trust; an unknown LoA maps to low

- **GIVEN** a `loaMap` mapping the broker's high assurance to `high`
- **WHEN** one callback presents that LoA and another presents an unmapped LoA
- **THEN** the first session gets trust `high` and the second gets trust `low` (under-privilege on ambiguity) — never the reverse
- @e2e exclude trust-mapping invariant — covered by PHPUnit; no UI surface

### Requirement: Dev-login stays debug-gated at trust low

`SessionController::devLogin()` MUST remain debug-gated and MUST issue trust
`low`. It MUST NOT be reachable on a non-debug production instance, and the SPA
MUST only surface it on a debug instance. It is unaffected by the OIDC edge.

#### Scenario: Dev-login is unavailable in production

- **GIVEN** a non-debug production instance
- **WHEN** `POST /portal/api/session/dev-login` is called
- **THEN** it is refused (debug-gated) and the SPA shows only the configured OIDC login buttons
- @e2e exclude debug-gating posture — covered by PHPUnit / config assertion; no UI surface

## Non-Functional Requirements

- **Security (ADR-005):** all ID-token checks are mandatory; `alg: none` is
  rejected; every failure is an identical generic error; the client secret is
  never exposed; the subjectRef is server-derived; ambiguous LoA under-privileges.
- **Standards:** OIDC authorization-code + PKCE (S256); RS256 verified via cached
  JWKS from the issuer's discovery document; provider presets for DigiD /
  eHerkenning / eIDAS over a generic RP.
- **Decoupling:** the RP is a standard OIDC client and MUST NOT hard-depend on
  OpenConnector (design.md).
- **Accessibility / i18n:** per-org login buttons follow NLDS with NL/EN provider
  labels.

## Acceptance Criteria

- Per-org OIDC config (issuer, clientId, secret, scopes, provider preset, claim
  map, loaMap) with the secret never rendered
- `start` issues a state+nonce+PKCE redirect; unconfigured → fail closed
- `callback` validates iss/aud/nonce/exp + RS256 via cached JWKS, rejects
  `alg:none`, maps claims, finds-or-creates the account, mints the HS256 session;
  every failure is an identical generic error
- LoA maps to trust, unmapped → `low`; the subjectRef is server-derived
- Dev-login stays debug-gated at trust `low`; the SPA enables per-org OIDC buttons
- README documents the OIDC config + flow; design.md records the DigiD
  Normenkader 3.0 + DPIA OPS gate and the no-hard-dependency-on-OpenConnector rule

## Notes

- Production DigiD go-live is gated by the DigiD Normenkader 3.0 assessment + DPIA
  — an OPS gate, not delivered by this code change (design.md).
- Machtigen / ketenmachtiging and a SAML broker profile are later slices.
