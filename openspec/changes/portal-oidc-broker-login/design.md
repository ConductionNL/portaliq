# Design: portal-oidc-broker-login

## Why an in-process OIDC RP, not an ExApp

The portal auth edge is deliberately in-process PHP (`PortalSessionService`
HS256, `PortalJwtService`, fail-closed middleware). Agent D confirms the broker
is *"an external OIDC/SAML hop feeding claims into the JWT — NOT an ExApp
(deliberate)."* The RP is the code that talks OIDC to that external broker and
then mints the SAME portal session the rest of the edge already understands. It
adds no new session format and no new trust vocabulary — it fills identity claims
into the existing `portalAccount` + `PortalSessionService::issueSession()` path.

## Broker-agnostic, provider-preset model

Direct DigiD/eHerkenning integration means SAML, PKIo certificates, and Logius
metadata management. A Signicat-class **identity broker** exposes DigiD /
eHerkenning / eIDAS as **plain OIDC** and keeps the PKIo/Logius burden on its
side. So portaliq implements a **generic OIDC RP** and treats DigiD/eHerkenning/
eIDAS as *provider presets* over that RP:

Per-organisation config (extending `PortalOrganisationConfigService`, replacing
the `idp` placeholder at line 166):

```
oidc:
  provider: digid | eherkenning | eidas | generic
  issuer:        https://broker.example/idp
  clientId:      <rp-client-id>
  clientSecret:  <app-config secret, never rendered>
  scopes:        [openid, ...]
  claimMap:
    identityRef:  <claim>       # e.g. pairwise sub / BSN-pseudonym / KvK
    subjectRef:   <claim | derive>
    audience:     <fixed | claim>
    identityType: <fixed by preset>
  loaMap:                        # broker LoA/acr → portal trust
    <acr-value>: low | substantial | high
```

`clientSecret` is stored via `IAppConfig` and NEVER returned by any endpoint or
rendered in the SPA. The provider preset fixes `identityType`
(`digid`/`eherkenning`/`eidas`) and sensible scope/claim defaults; `generic`
allows a fully custom broker.

## The flow

### start — `GET /portal/api/session/oidc/start?org=&provider=`

1. Resolve the org's OIDC config for the requested provider; if none, fail closed.
2. Generate `state`, `nonce`, and a PKCE `code_verifier`/`code_challenge` (S256).
3. Persist `state` → `{nonce, code_verifier, org, provider, returnTo}` in
   short-lived server-side storage (keyed, single-use, TTL-bounded).
4. 302 to the broker's `authorization_endpoint` with `client_id`,
   `redirect_uri`, `response_type=code`, `scope`, `state`, `nonce`,
   `code_challenge`, `code_challenge_method=S256`.

### callback — `GET /portal/api/session/oidc/callback`

1. Look up `state`; unknown / reused / expired `state` → fail closed (CSRF guard).
2. Exchange `code` at the `token_endpoint` with the stored PKCE `code_verifier`.
3. **Validate the ID token, all checks mandatory, any failure → identical generic
   error**:
   - `iss` equals the configured issuer;
   - `aud` contains the configured `clientId`;
   - `nonce` equals the stored nonce;
   - `exp`/`iat` within skew;
   - **RS256 signature** verified against the broker's JWKS, fetched from the
     discovery `jwks_uri` and **cached** (with key-rotation refresh).
4. Map claims per `claimMap` → `identityType`, `identityRef`, `subjectRef`,
   `audience`; map the broker LoA/`acr` per `loaMap` → portal trust
   `low|substantial|high`.
5. Find-or-create the `portalAccount` for `(identityType, identityRef, org)`;
   never trust a client-supplied subjectRef — it is server-derived from claims.
6. Mint the existing HS256 portal session via
   `PortalSessionService::issueSession()` with the derived subject, org, and trust.
7. Redirect to the SPA `returnTo` with the session established.

## Fail-closed discipline (ADR-005)

Every failure — unknown org/provider, bad/reused state, token-exchange error,
`iss`/`aud`/`nonce`/`exp` mismatch, signature failure, JWKS unavailable,
unmappable claims — returns the SAME generic error and mints NO session. No
response distinguishes *which* check failed (no oracle). The RS256 signature is
never skipped, `alg: none` is rejected, and the JWKS is only trusted from the
configured issuer's discovery document.

## Trust mapping

The broker's LoA (DigiD Basis/Midden/Substantieel/Hoog, eHerkenning EH2/EH3,
eIDAS low/substantial/high) maps onto the portal's existing `low|substantial|high`
via the per-org `loaMap`. An unmapped or missing LoA maps to the **lowest**
trust, never the highest — under-privilege on ambiguity. Downstream `minTrust`
re-checks (already enforced per handler) then gate collections/actions as today.

## Dev-login stays

`SessionController::devLogin()` remains debug-gated and issues trust `low`. It is
the local-development door and is unaffected; the SPA shows it only on a debug
instance. Production instances rely solely on the OIDC edge.

## OpenConnector: may replace, must not couple

OpenConnector may later host the broker hop (SessionController's original "awaiting
OpenConnector" note). This change does NOT wait for it and MUST NOT hard-depend on
it: the RP is a standard OIDC client that works against any conformant broker. If
OpenConnector later fronts the broker, it becomes just another OIDC issuer in the
per-org config — no portaliq code change to the session edge.

## Production go-live is an OPS gate, not a code task

Shipping DigiD to production requires the **DigiD Normenkader 3.0** annual
ICT-beveiligingsassessment by an RE auditor (report ≤2 months after activation)
**and a DPIA** (agent E D6). These are operational/compliance gates that run in
parallel with — and are NOT delivered by — this code change. The code edge can be
built and fully tested against a broker's **acceptance** environment before the
assessment/DPIA complete. This design.md records the gate so the change is not
mistaken for production DigiD authorisation.
