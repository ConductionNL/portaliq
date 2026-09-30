# Design: signin-session-idle-warning-and-sso

Read at portaliq development `eeda3fa`.

## What is there today

- `lib/Service/PortalJwtService.php:60` `DEFAULT_TTL = 7200`. `createSession()` (line 116) takes an optional `$ttl` and otherwise uses the default.
- `lib/Service/PortalSessionService.php:316` `mintSession()` calls `createSession()` without a TTL, so every bearer lives two hours. `refreshSession()` (line 470) rotates a valid bearer into a new one with a new `jti` and refuses once `time() - authTime` reaches `maxLifetimeSeconds()` (line 374), config `session_max_lifetime`, default 28800 (line 108).
- `lib/Controller/SessionController.php:658` `refresh()` answers `POST /portal/api/session/refresh` (`appinfo/routes.php:226`) with the new bearer or one generic 401. `index()` (line 155) answers `GET /portal/api/session` with `subjectRef`, `audience`, `organisation` and `trust`, and no expiry.
- `src/portal/App.jsx:36` `REFRESH_INTERVAL_MS = 25 * 60 * 1000`, and `App.jsx:168-174` calls `api.refreshSession()` on that interval for as long as a session exists, whether or not the resident does anything. `portalApi.refreshSession()` (`src/portal/lib/portalApi.js:633`) fails silently.
- `src/site/lib/authApi.js` keeps the bearer in `sessionStorage` (line 47) and never refreshes it.
- `lib/Service/OidcClientService.php:167` `discover()` keeps `authorization_endpoint`, `token_endpoint` and `jwks_uri` from the discovery document, and drops everything else. `buildAuthorizationUrl()` (line 226) sends no `prompt`.
- `SessionController::oidcCallback()` (line 370) returns the generic JSON error when the broker sends `error`, whatever the error is.
- `SessionController::logout()` (line 633) revokes the bearer's `portalSession` row and answers `{ok: true}`. The broker session is untouched.

## D1. The server enforces the idle window through the bearer's lifetime

A new app setting `session_idle_timeout`, in seconds, default 900, clamped to 300 through 3600. `mintSession()` passes it as the TTL, so a new or rotated bearer expires one idle window after it was minted.

No new state is needed. A bearer that nobody refreshes expires on the server, whatever the browser does. The alternative, a `lastSeenAt` on the `portalSession` row written on every request, costs an OpenRegister write per request and adds nothing the expiry does not already give.

`session_max_lifetime` keeps its meaning and its default. `refreshSession()` keeps refusing past it.

## D2. The browser refreshes on activity, not on a timer

Both SPAs record the time of the last key press, pointer press or touch in the page. When the bearer has less than half its window left and there has been activity since the last refresh, the SPA calls `POST /portal/api/session/refresh`. The fixed 25 minute interval in `App.jsx` goes.

Scrolling and mouse movement do not count. A page left open on a screen should still time out.

To schedule this, `GET /portal/api/session` also returns `expiresAt` (the bearer's `exp`), `hardExpiresAt` (`authTime + session_max_lifetime`) and `idleTimeout`. The refresh response returns the same three fields.

## D3. The warning dialog

Two minutes before `expiresAt`, or at 40 percent of the window when the window is under five minutes, the SPA opens a dialog:

- Title: "You will be signed out soon" (Dutch: "U wordt zo uitgelogd").
- Body: "You will be signed out in {time} because you have been inactive." (Dutch: "U wordt over {time} uitgelogd omdat u niets heeft gedaan.")
- Buttons: "Stay signed in" (Dutch: "Ingelogd blijven") and "Sign out" (Dutch: "Uitloggen").

The dialog has `role="alertdialog"`, moves focus to "Stay signed in", and announces the remaining time through a polite live region once a minute, not every second. This meets WCAG 2.2 success criterion 2.2.1, timing adjustable: a warning, and at least 20 seconds to extend with one action.

When `hardExpiresAt` comes before the next possible extension, the body reads "Your session ends in {time}. Sign in again to keep going." (Dutch: "Uw sessie stopt over {time}. Log opnieuw in om verder te gaan.") and "Stay signed in" is not shown.

At expiry the SPA drops the token and shows the login screen with "You were signed out because you were inactive." (Dutch: "U bent uitgelogd omdat u een tijd niets heeft gedaan.")

The dialog lives in its own component file, `src/portal/components/IdleWarningDialog.jsx`, and the site renderer's in `src/site/components/IdleWarningDialog.vue`, so neither parent carries modal markup inline.

## D4. Several tabs agree

The portal SPA keeps its bearer in `localStorage`, shared between tabs. A refresh in one tab rotates the bearer and revokes the old one, and `portalApi` reads the current token on every call. Each tab also listens for the `storage` event and reschedules its warning from the new expiry, so activity in one tab keeps the others from warning.

The site renderer keeps its bearer per tab in `sessionStorage`, so each tab times out on its own. That is the existing choice in `authApi.js:42-46`, and this change does not revisit it.

## D5. Silent sign-in through the organisation's broker

`GET /portal/api/session/oidc/start` accepts `silent=1`. With it, `buildAuthorizationUrl()` adds `prompt=none`, and the state row records `silent: true` in a new optional `silent` property on `portalOidcState` (`lib/Settings/portaliq_register.json:1376`).

On the callback, a silent row that comes back with `error=login_required`, `interaction_required`, `consent_required` or `account_selection_required` redirects to the portal's login screen with no message. Any other error, or any error on a non-silent row, keeps today's generic failure.

The organisation's other services link to the start address with `silent=1`. The portal SPA itself does not try a silent sign-in on every load: an organisation turns that on with a `silentSignIn` provider in its presentation override, and the SPA tries it once per browser session, remembered in `sessionStorage`, so a refused attempt never loops.

## D6. Signing out ends the broker session when the broker offers it

`discover()` also keeps `end_session_endpoint` when present. `logout()` revokes the `portalSession` row as today and then answers with a `logoutUrl` when the session was minted through an OIDC broker that announced one. The SPA navigates to it. The URL carries `client_id` and `post_logout_redirect_uri` (the portal page), following OpenID Connect RP-Initiated Logout 1.0.

Portaliq keeps no ID token, so it sends no `id_token_hint`. A broker may then ask the resident to confirm the sign-out. That is the broker's screen and its choice.

To know the broker, the session needs the provider it came from. `mintSession()` adds a `provider` claim to the bearer (`digid`, `eherkenning`, `eidas`, `generic`, or absent for dev-login and the Nextcloud mode), and `resolveFromBearer()` returns it.

## Risks

- **Third-party front-ends lose sessions sooner.** A bearer now lives 15 minutes, not two hours. A front-end on the headless API that never calls refresh signs its users out after one idle window. The docs page says so, and the setting can go up to an hour.
- **More refresh writes.** An active resident rotates the bearer about every seven minutes instead of every 25. Each rotation writes a `portalSession` row and an audit entry. At portal scale that is small, and it is the price of an idle limit the server enforces.
- **Brokers differ on `prompt=none`.** A broker that ignores it shows its login page instead. The resident still gets in; they only type once more.

## What this change does not do

- It does not add single sign-on or single logout to the integriq route.
- It does not end the broker session on an inactivity sign-out.
- It does not change `session_max_lifetime`, the refresh rotation or the revocation model.
