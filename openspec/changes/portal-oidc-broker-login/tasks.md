# Tasks: portal-oidc-broker-login

> Generic broker-agnostic OIDC RP: per-org config, start (state+nonce+PKCE),
> callback (validate ID token, map claims, mint the existing HS256 session),
> fail-closed everywhere, SPA login buttons. Implementation-ordered.

## Implementation Tasks

- [ ] T01 — Extend `PortalOrganisationConfigService` to resolve a per-org `oidc` config (issuer, clientId, clientSecret-via-`IAppConfig`, scopes, provider preset, claimMap, loaMap), replacing the `idp` placeholder (line 166); never return the secret.
- [ ] T02 — Add short-lived single-use `state` storage (`state` → `{nonce, code_verifier, org, provider, returnTo}`, TTL-bounded) via app cache / a small store service.
- [ ] T03 — Create `lib/Service/OidcClientService.php`: build the authorization request (state, nonce, PKCE S256), exchange the code at the token endpoint, and fetch + cache the issuer's JWKS with key-rotation refresh.
- [ ] T04 — Implement ID-token validation in `OidcClientService`: iss, aud, nonce, exp/iat skew, RS256 signature via cached JWKS; reject `alg:none`; identical generic failure on any error.
- [ ] T05 — Implement claim mapping (→ identityType/identityRef/subjectRef/audience) and LoA→trust mapping (unmapped → `low`); provider presets for digid/eherkenning/eidas + generic.
- [ ] T06 — Add `GET /portal/api/session/oidc/start` route + `SessionController::oidcStart()` (`#[PublicPage]` `#[NoCSRFRequired]`): resolve config (fail closed), build + store state, 302 to the broker.
- [ ] T07 — Add `GET /portal/api/session/oidc/callback` route + `SessionController::oidcCallback()`: look up state (unknown/reused/expired → fail closed), exchange code, validate token, map claims, find-or-create `portalAccount`, mint the session via `PortalSessionService::issueSession()`, redirect to the SPA.
- [ ] T08 — Find-or-create `portalAccount` keyed on `(identityType, identityRef, org)`; subjectRef server-derived from claims only, never client-supplied.
- [ ] T09 — SPA: enable the per-org login buttons (currently disabled) with provider labels from the org config; wire them to `/portal/api/session/oidc/start`; keep dev-login only on a debug instance.
- [ ] T10 — Unit test `OidcClientServiceTest`: valid token passes; each single-check failure (iss/aud/nonce/exp/signature/`alg:none`/reused-state/JWKS-unavailable) yields the identical generic error and no session.
- [ ] T11 — Unit test claim + LoA mapping: server-derived subjectRef; recognised LoA → its trust; unmapped LoA → `low`.
- [ ] T12 — Unit test `SessionControllerTest`: start redirect params + stored state; callback happy path mints a session against a stub broker + JWKS; unconfigured org fails closed; dev-login debug-gated.
- [ ] T13 — Add Playwright e2e: against a stub/acceptance broker, complete start→callback and land authenticated in the SPA; confirm login buttons render per org config (closes the `@e2e exclude` markers on the config + happy-path scenarios).
- [ ] T14 — Document the OIDC per-org config, the flow, and the security invariants in `README.md`; record the DigiD Normenkader 3.0 + DPIA OPS gate and the no-hard-dependency-on-OpenConnector rule (already in design.md); add the requirements to the canonical `openspec/specs/supplier-portal` spec on sync.
- [ ] T15 — Run `composer check:strict` and `npm run lint` green; run Hydra gates (route-auth, semantic-auth, unsafe-auth-resolver, security-change-has-tests, spec-coverage, e2e-coverage). Flag an ADR-005 security review of the token-validation + secret-handling paths before merge.
