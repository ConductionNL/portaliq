# Proposal: signin-session-idle-warning-and-sso

## Why

Two gaps on the sign-in edge, both rated `no` in the portaliq matrix.

**Row `cmp-sig-idle-warning`**, "Get a warning shortly before you are signed out for inactivity, and stay signed in with one click." The matrix `built.note` reads: "The session is slid forward silently while the tab is open; there is no inactivity timer, no warning dialog and no 'stay signed in' control." A resident who walks away from a shared computer stays signed in for up to eight hours, as long as the tab stays open.

The row's origin is recorded as `competitor`, with originUrl https://github.com/maykinmedia/open-inwoner/blob/v2.4.3/src/open_inwoner/extended_sessions/tests/test_views.py#L17 and the note "test name and i18n string in two competitors". No demand row is attached. The competitor cells rated `yes`, quoted from `gap-rows.json`:

- Open Inwoner Platform: "src/open_inwoner/extended_sessions/templates/sessions/session_timeout.html:5; src/open_inwoner/js/components/session/index.js:10 SessionTimeout; src/open_inwoner/extended_sessions/views.py:5 RestartSessionView [reached on every signed-in page]". No URL recorded.
- NL Portal: "frontend/packages/authentication/src/components/ProtectedApp.tsx:52 warning timer five minutes before logout; :57 modal with stay signed in or log out [reached on every signed-in page]". No URL recorded.
- xxllnc Zaken PIP: "backend/perl-api/frontend/zaaksysteem/src/js/nl/mintlab/utils/directives/zsSessionTimeout.js:36 'Uw sessie dreigt te verlopen' with 'Verleng sessie'; backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/layouts/pip.tt:16 [reached on every PIP page; was unknown from docs]". No URL recorded.

**Row `cmp-sig-sso`**, "Move between the portal and the organisation's other citizen services without signing in again." The matrix `built.note` reads: "No evidence of single sign-on with any external citizen-service beyond the identity broker exchange itself (which authenticates INTO portaliq, not FROM it to somewhere else)." No demand row is attached. The competitor cells rated `yes`:

- MijnOverheid: "https://www.binnenlandsbestuur.nl/digitaal/digid-eenmalig-inloggen-klaar: DigiD 'eenmalig inloggen' lets citizens 'slechts één keer met hun DigiD in te loggen' across government sites, MijnOverheid named as example [source dated 2009]". URL: https://www.binnenlandsbestuur.nl/digitaal/digid-eenmalig-inloggen-klaar
- Liferay DXP: "https://learn.liferay.com/w/dxp/security-and-administration/security/configuring-sso/authenticating-with-saml/saml-authentication-process-overview describes SAML Web Browser SSO and single logout across multiple service providers; https://learn.liferay.com/w/dxp/security-and-administration/security/configuring-sso/using-openid-connect covers OIDC providers." URLs: https://learn.liferay.com/w/dxp/security-and-administration/security/configuring-sso/authenticating-with-saml/saml-authentication-process-overview and https://learn.liferay.com/w/dxp/security-and-administration/security/configuring-sso/using-openid-connect

Both rows are in the `signin` area, the portaliq matrix's core area.

## What changes

- **An inactivity limit the server enforces.** A portal bearer lives as long as the idle window, 15 minutes by default. Only activity refreshes it, so an unattended tab stops being a session.
- **A warning before the limit.** Two minutes before sign-out the portal shows a dialog with a countdown, "Stay signed in" and "Sign out". One click extends the session.
- **A clear message afterwards.** A resident signed out for inactivity lands on the login screen and is told why.
- **The eight-hour cap stays.** When the cap is near, the warning says the session cannot be extended and offers to sign in again.
- **Silent sign-in from the organisation's other services.** A link from another service of the organisation can open the portal through the organisation's OIDC broker without a login prompt, when the broker already knows the resident. If it does not, the resident sees the ordinary login screen, not an error.
- **Signing out ends the shared sign-in.** When the broker offers a logout endpoint, "Sign out" ends the broker session too, so the next person at a shared computer is not signed in elsewhere.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `cmp-sig-idle-warning` | Get a warning shortly before you are signed out for inactivity, and stay signed in with one click. | no | An inactivity limit, the warning dialog and the one-click extension. |
| portaliq | `cmp-sig-sso` | Move between the portal and the organisation's other citizen services without signing in again. | no | Silent sign-in through the organisation's broker, and a sign-out that ends the shared sign-in. |

## Existing work it builds on

- `openspec/changes/archive/2026-07-24-portal-session-hardening-v2`: the sliding refresh (`POST /portal/api/session/refresh`) and the absolute cap `session_max_lifetime`. This change makes the refresh depend on activity and shortens the bearer to the idle window.
- `openspec/specs/supplier-portal/spec.md`, requirement "Session refresh rotates the token within an absolute cap". Kept as it is.
- `openspec/changes/portal-auth-edge-session-hardening` (open): the `portalSession` row and revocation that logout uses.
- `openspec/changes/archive/2026-07-24-portal-oidc-broker-login`: the OIDC start and callback this change teaches `prompt=none` and logout.

## Out of scope

- Single sign-on and single logout on the integriq route (`signin-integriq-broker-login`). Integriq's spec names single logout as vendor-dependent and has no silent sign-in contract yet.
- Signing the resident in to the other service. That service does it against the same broker, on its own.
- Ending the broker session on an inactivity sign-out. Nobody is at the screen to follow the redirect; the portal session ends and the broker's own timeout applies.
- The headless content API used by third-party front-ends. They keep their own session handling, as `src/site/lib/authApi.js` says of itself.
