# portal-nextcloud-account-login Specification

## Purpose
A person who already has an account on the Nextcloud instance signs in to a
portal with it, without a second password. Nextcloud checks the password; the
portal only turns that Nextcloud session into a portal session. Written after
the fact (7 Oct 2026) for parity row `sig-nextcloud-login`. It describes the
code on development: `lib/Controller/SessionController.php` `nextcloud()`
(route `session#nextcloud`, `GET /portal/api/session/nextcloud` in
`appinfo/routes.php`) and `src/site/lib/authApi.js` `signInRoutes()`, which
draws the "Log in with your account" choice. Which sign-in choices a portal
draws at all is `portaliq-cms` "A portal MUST offer only the sign-in routes it
declares".

## Requirements

### Requirement: The portal offers the Nextcloud way in only when it declares it (REQ-NCL-001)

The site SHALL draw "Log in with your account", pointing at
`/portal/api/session/nextcloud` with the portal slug, only when the portal's
`authentication.modes` includes `nextcloud`. The endpoint SHALL refuse a portal
that does not declare the mode with 404 `mode_not_offered`, the same status as an
unknown portal (404 `portal_not_found`), so a visitor who types the address
cannot enter through it and cannot tell the two refusals apart by status.

#### Scenario: A portal without the mode
- **GIVEN** a portal whose `authentication.modes` is `["oidc"]`
- **WHEN** a signed-in Nextcloud user opens `/portal/api/session/nextcloud?portal=<slug>`
- **THEN** the answer is 404 `mode_not_offered` and no portal session is issued

#### Scenario: The button follows the declaration
- **GIVEN** a portal whose `authentication.modes` includes `nextcloud`
- **WHEN** the site renders its sign-in menu
- **THEN** it shows "Log in with your account" linking to the Nextcloud sign-in route

### Requirement: Nextcloud checks the password, never the portal (REQ-NCL-002)

The endpoint SHALL NOT be a public page and SHALL read only the caller's current
Nextcloud session. A caller without one SHALL be redirected (302) to Nextcloud's
own login form with `redirect_url` set to this request, and SHALL come back to
the endpoint after signing in.

#### Scenario: Not yet signed in to Nextcloud
- **GIVEN** a visitor with no Nextcloud session
- **WHEN** they choose "Log in with your account"
- **THEN** they are sent to Nextcloud's login form, and after signing in they return to the endpoint

### Requirement: Only an existing, active portal account gets a session (REQ-NCL-003)

The endpoint SHALL look up the `portalAccount` whose `subjectRef` is the
Nextcloud user id and SHALL NOT create one. A missing or non-active account
SHALL answer 403 `no_portal_account`. For an active account it SHALL issue a
portal session with the account's audience (default `client`), its
organisation, the role `<audience>:read` and trust `low`, never higher, so a
portal that gates content on DigiD-level trust is not opened by a Nextcloud
password. When no session can be issued it SHALL answer 503 `not_configured`.

#### Scenario: A Nextcloud user without a portal account
- **GIVEN** a Nextcloud user with no `portalAccount` for their user id
- **WHEN** they sign in through the Nextcloud way in
- **THEN** the answer is 403 `no_portal_account`

#### Scenario: The session's trust stays low
- **GIVEN** an active portal account for the Nextcloud user
- **WHEN** the session is issued
- **THEN** its trust is `low`, so content with `minTrust: substantial` stays closed

### Requirement: The bearer travels in the fragment (REQ-NCL-004)

On success the endpoint SHALL redirect (302) to `returnTo`, or to
`/apps/portaliq/site?portal=<slug>` when none is given, with the bearer in the
URL fragment (`#token=`), so it is never sent to a server or written to a log.

#### Scenario: Back on the portal, signed in
- **GIVEN** an active portal account and a portal declaring `nextcloud`
- **WHEN** the user completes the Nextcloud way in without `returnTo`
- **THEN** the browser lands on `/apps/portaliq/site?portal=<slug>#token=<bearer>`
