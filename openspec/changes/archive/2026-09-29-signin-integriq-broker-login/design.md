# Design: signin-integriq-broker-login

Read at portaliq development `eeda3fa` and integriq development on 2026-09-27.

## What is there today

- `appinfo/routes.php:232-233` registers `session#oidcStart` (`GET /portal/api/session/oidc/start`) and `session#oidcCallback` (`GET /portal/api/session/oidc/callback`).
- `lib/Controller/SessionController.php:284` `oidcStart(string $org, string $provider)` asks `PortalOrganisationConfigService::isLoginProviderAllowed()` first, resolves the config, discovers the broker, writes a `portalOidcState` row and redirects with a 302.
- `lib/Controller/SessionController.php:370` `oidcCallback()` consumes the state row, exchanges the code, verifies the ID token, maps claims, calls `PortalAccountService::findOrCreate()` (`lib/Service/PortalAccountService.php:119`) and `PortalSessionService::issueSession()` (`lib/Service/PortalSessionService.php:259`), then redirects with the bearer in the URL fragment. Every failure returns `oidcGenericError()` (`SessionController.php:487`), a JSON 400 with `{"error":"oidc_failed"}`.
- `lib/Service/PortalOrganisationConfigService.php:104` holds the closed provider list `['digid', 'eherkenning', 'eidas', 'generic']`. `resolve()` (line 147) returns `oidcProviders` for the SPA, built by `configuredOidcProviders()` (line 377). Client secrets live in a sensitive `IAppConfig` entry keyed `oidc_secret_<organisationUuid>_<provider>` (lines 305 to 344), never in the presentation override blob.
- `lib/Service/OidcClaimMapperService.php:74` holds the presets. DigiD and eIDAS map to audience `client`, eHerkenning to `supplier`.
- `lib/Service/OidcStateStoreService.php:91` `create()` and `:140` `consume()` store a single-use row in the `portalOidcState` schema (`lib/Settings/portaliq_register.json:1376`), TTL 600 seconds (`:61`). `nonce` and `codeVerifier` are required properties.
- `src/portal/App.jsx:313` `oidcLogin(provider)` navigates to `api.oidcStartUrl(provider)` (`src/portal/lib/portalApi.js:699`), which builds `/session/oidc/start?org=&provider=`. The buttons render at `App.jsx:361-375`.
- `src/site/lib/authApi.js:155` `signInRoutes()` builds the site renderer's links as `/session/oidc/start?provider=<mode>&portal=<slug>&returnTo=<path>`. `oidcStart()` reads `org`, not `portal`, so that link reaches the start with an empty organisation. That is a finding outside this change; the broker start below accepts both.
- Integriq development: `appinfo/routes.php:372` `idpBroker#exchange` at `POST /api/idp/envelope/exchange`. `IdpBrokerController::exchange()` reads `code` and `consumer` from the body and the consumer secret from `Authorization: Bearer <secret>`, and answers `{envelope: <jws>}` or one undifferentiated 401. `SubjectEnvelope::toClaims()` carries `sub`, `subType` (`bsn-pseudonym`, `kvk`, `rsin` or `eidas-person-identifier`), `provider`, `audience` (the consumer id), `organisation`, `trust`, `use` = `idp-envelope`, `iss` = `openconnector-idp-broker`, plus `jti`, `iat`, `exp` at most 60 seconds after `iat`. The envelope `jti` is burnt by integriq when it verifies the envelope at redemption.

## D1. The route is chosen per organisation and per provider, explicitly

The organisation's presentation override gains a `loginRoutes` map, for example `{"digid": "broker", "eherkenning": "oidc"}`. A provider missing from the map uses `oidc`, which is what every organisation does today, so nothing changes on upgrade.

The alternative was "broker wins when both are configured". That is a silent choice made on the operator's behalf. An explicit map says in one place which route a resident will take.

`configuredOidcProviders()` becomes the list of configured login providers. Each entry gains `route`. A provider appears only when its chosen route is fully configured: for `oidc` the existing three fields, for `broker` the start address, the exchange address, the consumer id and the consumer secret.

## D2. Two new routes, and the SPAs follow the `route` field

- `GET /portal/api/session/broker/start?org=&portal=&provider=` on `SessionController::brokerStart()`.
- `GET /portal/api/session/broker/callback?state=&code=` on `SessionController::brokerCallback()`.

Both are `#[PublicPage]`, `#[NoCSRFRequired]` and `#[AnonRateLimit(limit: 30, period: 60)]`, the same posture as the OIDC pair (ADR-082). They are registered next to the OIDC pair, before the `/portal/{path}` catch-all.

`brokerStart()` accepts `org` or `portal`. When only `portal` is given it resolves the organisation through `PortalResolver::resolve()`, which `SessionController::portalFor()` (line 613) already wraps. The portal record's `organisation` field is the slug.

`src/portal/lib/portalApi.js` gains `loginStartUrl(provider, route)`. `App.jsx` `oidcLogin` becomes `startLogin(p)` and picks the start address by `p.route`. `src/site/lib/authApi.js` `signInRoutes()` gets the provider routes from the site config and does the same.

## D3. The state row records which route it belongs to

`portalOidcState` gains an optional `route` property, `oidc` or `broker`, absent meaning `oidc`. For a broker row, `nonce` holds the relay state sent to integriq, and `codeVerifier` is not required. The schema moves `codeVerifier` out of `required`; `oidcCallback()` keeps refusing a row without one.

Each callback refuses a row written for the other route. A state minted for the OIDC route cannot complete a broker login, and the reverse.

## D4. The code is redeemed over HTTP, because that is integriq's contract

ADR-041 sends cross-app commands through typed events and rejects internal HTTP between apps on one instance. This exchange is not a command on shared data. It is the redemption half of an authentication handoff whose contract integriq wrote as a server-to-server call with a per-consumer secret, so the broker can run on another instance than the portal.

Portaliq posts `{code, consumer}` to the configured exchange address with `Authorization: Bearer <secret>`, using `IClientService` with the same 10 second timeout `OidcClientService` uses (`lib/Service/OidcClientService.php:88`). Anything but a 200 with an `envelope` string ends the login.

## D5. Portaliq checks the claims, and says why it does not check the signature

The envelope is HS256, signed with integriq's own key. Checking the signature in portaliq would mean holding that key, and a holder of it can mint envelopes. Portaliq therefore trusts the envelope because of the channel it arrived on: TLS to a configured address, authenticated with portaliq's own secret, redeemed once.

Portaliq still decodes and checks every claim it acts on, and refuses on any mismatch:

- `use` is `idp-envelope` and `iss` is `openconnector-idp-broker`.
- `audience` equals portaliq's configured consumer id.
- `organisation` equals the organisation the state row was written for.
- `provider` equals the provider the state row was written for.
- `exp` is in the future and at most 60 seconds after `iat`.
- `trust` is one of `low`, `substantial`, `high`. Anything else is normalised to `low` by `PortalSessionService::normaliseTrust()` (line 200), as integriq's spec asks of consumers.

The envelope is never stored and never used as a bearer. `resolveFromBearer()` already refuses a token carrying a non-empty `use` claim.

## D6. The envelope maps onto the existing account and session

- `identityType` is the envelope's `provider` (`digid`, `eherkenning` or `eidas`), all three already in the `portalAccount.identityType` enum (`portaliq_register.json:758`).
- `identityRef` is the envelope's `sub`, which is a pseudonym, a KvK number, an RSIN or an eIDAS person identifier. The schema already describes `identityRef` as "Pseudonymous identity reference from the IdP [...] Not a raw BSN."
- `audience` comes from the provider preset in `OidcClaimMapperService::PRESETS`, so a resident gets `client` and a business user gets `supplier` on either route.
- `trust` is the envelope's `trust`. No LoA table in portaliq: integriq already mapped the assurance level.

`findOrCreate()` and `issueSession()` are called exactly as `oidcCallback()` calls them. The integriq route supplies no e-mail address, so no verified e-mail is passed and no pending account is claimed by address.

## D7. A failed login returns to the login screen with one message

The OIDC callback answers a browser navigation with raw JSON. The broker callback instead redirects to the portal with the fragment `#signin=failed`, carrying no reason. The login screen shows one message for every cause:

- English: "Signing in did not work. Try again or choose another way in."
- Dutch (the portal SPA's default): "Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier."

The fragment is read and stripped the same way `#token=` is today.

## D8. Where the secret lives

The consumer secret is stored like the OIDC client secret: a sensitive `IAppConfig` entry keyed `broker_secret_<organisationUuid>`, written through a setter on `PortalOrganisationConfigService` and never returned by any endpoint. ADR-064 moves secrets to a `credentialRef` in the credential broker. When portaliq adopts it, both secrets move together; doing one alone would leave two custody models in one service.

## Risks

- **Accounts do not carry over between routes.** A resident who signed in through an organisation's own OIDC broker has an `identityRef` taken from that broker's claim. Integriq gives a per-organisation pseudonym. The two never match, so switching an organisation to the broker route creates new accounts. The admin settings text says so next to the route choice.
- **The broker route is inert until integriq's sibling half lands.** Without integriq's start endpoint there is nothing to redirect to. Configuration validation refuses a `broker` route without a start address, so no button appears instead of a button that fails.
- **Clock skew on `exp`.** A 60 second envelope and a slow exchange can expire in transit. Portaliq allows no extra skew beyond what integriq allows, and the failure is the ordinary failed-login message.

## What this change does not do

- It does not speak SAML or OIDC to a government login. Integriq does.
- It does not change the OIDC route, its presets or its error response.
- It does not add a login method list to the CMS schema. `authentication.modes` keeps its enum; `digid`, `eherkenning` and `eidas` already exist there.
