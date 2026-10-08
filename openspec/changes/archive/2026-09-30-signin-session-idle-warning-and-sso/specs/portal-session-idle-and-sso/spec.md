---
status: proposed
---

# Spec: portal-session-idle-and-sso

## Purpose

A resident who walks away is signed out, gets a warning first, and can stay signed in with one click. A resident who already signed in at another service of the organisation reaches the portal without signing in again, and one sign-out ends both. Closes portaliq matrix rows `cmp-sig-idle-warning` and `cmp-sig-sso`.

## ADDED Requirements

### Requirement: A portal session ends after the idle window (REQ-SIS-001)

Every portal bearer, new or rotated, SHALL expire one idle window after it was minted. The idle window SHALL be the app setting `session_idle_timeout`, default 900 seconds, clamped to 300 through 3600. The absolute cap `session_max_lifetime` SHALL still apply. `GET /portal/api/session` and `POST /portal/api/session/refresh` SHALL report `expiresAt`, `hardExpiresAt` and `idleTimeout`.

#### Scenario: An unattended bearer stops working
- **GIVEN** a resident signed in with the default idle window
- **WHEN** 15 minutes pass without a refresh
- **THEN** `GET /portal/api/session` with that bearer answers 401

#### Scenario: The session reports when it ends
- **GIVEN** a signed-in resident
- **WHEN** the portal SPA calls `GET /portal/api/session`
- **THEN** the answer carries `expiresAt`, `hardExpiresAt` and `idleTimeout`

### Requirement: Only activity keeps a session alive (REQ-SIS-002)

The portal SPA and the site renderer SHALL refresh the bearer only when the resident pressed a key, pressed a pointer or touched the page since the last refresh, and the bearer has less than half its window left. They SHALL NOT refresh on a fixed timer. Scrolling and pointer movement SHALL NOT count as activity. In the portal SPA, a refresh in one tab SHALL reschedule the warning in every other tab.

#### Scenario: Typing keeps a resident signed in
- **GIVEN** a resident filling in a form for 40 minutes with a 15 minute idle window
- **WHEN** they keep typing
- **THEN** they stay signed in and see no warning

#### Scenario: An open tab alone does not keep a session
- **GIVEN** a resident who leaves the portal open and does nothing
- **WHEN** the idle window passes
- **THEN** the portal shows the login screen

#### Scenario: Activity in another tab counts
- **GIVEN** a resident with the portal open in two tabs, active in the first
- **WHEN** the second tab's warning time arrives
- **THEN** the second tab shows no warning

### Requirement: A warning comes before the sign-out (REQ-SIS-003)

Two minutes before the bearer expires, or at 40 percent of the window when the window is under five minutes, the portal SHALL show a dialog saying the resident will be signed out, with the time left, a "Stay signed in" button and a "Sign out" button. Focus SHALL move to "Stay signed in". The time left SHALL be announced to assistive technology at most once a minute. "Stay signed in" SHALL refresh the session and close the dialog. After an inactivity sign-out the login screen SHALL say: "You were signed out because you were inactive."

#### Scenario: One click keeps the resident signed in
- **GIVEN** a resident who has been inactive for 13 minutes of a 15 minute window
- **WHEN** the warning appears and they press "Stay signed in"
- **THEN** the dialog closes and the session runs for another full window

#### Scenario: The resident signs out from the warning
- **GIVEN** the warning dialog is open
- **WHEN** the resident presses "Sign out"
- **THEN** the session is revoked and the login screen is shown

#### Scenario: The login screen says why
- **GIVEN** a resident who ignored the warning
- **WHEN** the window passes
- **THEN** the login screen shows "You were signed out because you were inactive."

### Requirement: The absolute cap is stated, not extended (REQ-SIS-004)

When the absolute cap ends the session before another extension is possible, the warning SHALL say the session ends and that the resident must sign in again, and SHALL NOT offer "Stay signed in".

#### Scenario: Near the cap there is nothing to extend
- **GIVEN** a resident seven hours and 58 minutes into an eight hour session
- **WHEN** the warning appears
- **THEN** it says the session ends and offers only to sign in again

### Requirement: A resident known to the broker reaches the portal without signing in again (REQ-SIS-005)

`GET /portal/api/session/oidc/start` SHALL accept `silent=1` and SHALL then ask the broker for a sign-in without a prompt. When the broker answers that the resident must interact (`login_required`, `interaction_required`, `consent_required` or `account_selection_required`), the portal SHALL show its login screen with no error. Every other broker error SHALL keep the generic failure. The portal SPA SHALL attempt a silent sign-in on load only when the organisation turned it on, and at most once per browser session.

#### Scenario: Coming from another service of the organisation
- **GIVEN** a resident signed in with DigiD at the organisation's broker for another service
- **WHEN** they follow that service's link to the portal with `silent=1`
- **THEN** they land in the portal signed in, without a login prompt

#### Scenario: The broker does not know the resident
- **GIVEN** a resident with no session at the broker
- **WHEN** they follow a `silent=1` link to the portal
- **THEN** they see the portal login screen with no error message

#### Scenario: A refused silent attempt does not loop
- **GIVEN** an organisation with silent sign-in on and a resident unknown to the broker
- **WHEN** the resident reloads the portal twice
- **THEN** the portal tries the silent sign-in once and then shows the login screen

### Requirement: Signing out ends the shared sign-in (REQ-SIS-006)

`DELETE /portal/api/session` SHALL revoke the portal session and, when that session came from an OIDC broker that announces an `end_session_endpoint`, SHALL return a `logoutUrl` carrying `client_id` and `post_logout_redirect_uri`. The portal SPA and the site renderer SHALL send the browser there. A session from dev-login, the Nextcloud mode or a broker without that endpoint SHALL get no `logoutUrl`.

#### Scenario: One sign-out on a shared computer
- **GIVEN** a resident signed in with DigiD through the organisation's broker
- **WHEN** they press "Sign out" in the portal
- **THEN** the portal session is revoked and the browser visits the broker's logout endpoint, then returns to the portal

#### Scenario: A broker without logout
- **GIVEN** a broker whose discovery document has no `end_session_endpoint`
- **WHEN** the resident signs out
- **THEN** the portal session is revoked and the resident stays on the portal login screen
