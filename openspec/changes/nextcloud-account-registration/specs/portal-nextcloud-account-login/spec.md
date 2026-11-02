## MODIFIED Requirements

### Requirement: Only an existing, active portal account gets a session (REQ-NCL-003)

The endpoint SHALL look up the `portalAccount` whose `subjectRef` is the
Nextcloud user id. It SHALL NOT create one, with one exception: when none exists
and the portal declares `authentication.registration.residentGroup` and the user
is a member of that Nextcloud group, it SHALL write the account through the
provisioner of `an-app-provisions-a-nextcloud-account` (app id `portaliq`): active,
`subjectRef` the user id, the portal's organisation, the audience
`authentication.registration.residentAudience` or `citizen` when none is
declared, the user's e-mail address stored as unverified. A refusal by the
provisioner (an account in the organisation holding the same address, a portal
without an organisation) SHALL leave the user without an account. A missing or
non-active account SHALL answer 403 `no_portal_account`. For an active account it
SHALL issue a portal session with the account's audience (default `client`), its
organisation, the role `<audience>:read` and trust `low`, never higher, so a
portal that gates content on DigiD-level trust is not opened by a Nextcloud
password. When no session can be issued it SHALL answer 503 `not_configured`.

#### Scenario: A Nextcloud user without a portal account
@e2e exclude PHPUnit through the real controller: tests/Unit/Controller/SessionControllerTest.php and tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** a Nextcloud user with no `portalAccount` for their user id, outside any resident group the portal declares
- **WHEN** they sign in through the Nextcloud way in
- **THEN** the answer is 403 `no_portal_account` and no account is written

#### Scenario: A resident signs in for the first time
@e2e exclude PHPUnit through the real controller and provisioner: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php; the live round trip needs a tenant with the registration app and a mail server
- **GIVEN** a portal declaring `nextcloud` and `registration.residentGroup: inwoners`, and a Nextcloud user `sanne` in group `inwoners` without a portal account
- **WHEN** `sanne` signs in through the Nextcloud way in
- **THEN** an active account with `subjectRef` `sanne`, audience `citizen` and the portal's organisation is written
- **AND** the session has audience `citizen`, role `citizen:read` and trust `low`

#### Scenario: Somebody else holds the address
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** a resident whose e-mail address a waiting account in the portal's organisation already holds under another subjectRef
- **WHEN** they sign in through the Nextcloud way in
- **THEN** the answer is 403 `no_portal_account` and nothing is written

#### Scenario: The session's trust stays low
@e2e exclude PHPUnit: tests/Unit/Controller/SessionControllerTest.php
- **GIVEN** an active portal account for the Nextcloud user
- **WHEN** the session is issued
- **THEN** its trust is `low`, so content with `minTrust: substantial` stays closed

## ADDED Requirements

### Requirement: The sign-in page leads to Nextcloud's own registration (REQ-NCL-005)

For a portal that offers the `nextcloud` mode and declares
`authentication.registration.residentGroup`, the site config SHALL carry
`registerUrl`, the address of the Nextcloud registration app's e-mail form, when
that app is enabled and its `registered_user_group` setting names exactly the
declared group. Otherwise `registerUrl` SHALL be `''`. The address SHALL be built
on the server from the route name and SHALL NOT be taken from the request. The
sign-in page SHALL show "Account aanmaken" on the account route's card only when
`registerUrl` is set, and never on the demo sign-in. A portal whose only e-mail
way in is the e-mail link SHALL NOT get the link (REQ-IWI-014). Portaliq SHALL NOT
receive, store or check a password.

#### Scenario: A portal with residents
@e2e exclude PHPUnit and node: tests/Unit/Controller/ContentControllerTest.php and tests/nextcloud-registration-link.spec.mjs; the click-through needs a tenant with the registration app
- **GIVEN** a portal declaring `nextcloud` and `registration.residentGroup: inwoners`, and the registration app enabled with `registered_user_group` `inwoners`
- **WHEN** a visitor opens the sign-in page
- **THEN** the account route's card shows "Account aanmaken" leading to the registration app's form

#### Scenario: The registration app fills another group, or is off
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** the registration app is disabled, or puts new users in `none` or another group
- **WHEN** the site config is served
- **THEN** `registerUrl` is `''` and no link is drawn

#### Scenario: An e-mail link portal
@e2e exclude PHPUnit and node: tests/Unit/Controller/ContentControllerTest.php and tests/nextcloud-registration-link.spec.mjs
- **GIVEN** a portal whose modes are `["email-link"]` and that names a resident group
- **WHEN** the site config is served
- **THEN** `registerUrl` is `''` and the sign-in page shows no "Account aanmaken"

### Requirement: A resident who opens the workplace is sent back to their portal (REQ-NCL-006)

When a signed-in Nextcloud user who is a member of the resident group of a
published portal opens portaliq's app page, portaliq SHALL redirect (302) to that
portal's account route (`/portal/api/session/nextcloud?portal=<slug>`), built from
the route name and the portal's slug. An administrator, and every user outside
all resident groups, SHALL get the app page as before.

#### Scenario: Back from activation
@e2e exclude PHPUnit through the real controller: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php; the live hop from the registration app needs the tenant's `defaultapp` setting
- **GIVEN** the resident `sanne` in group `inwoners` of portal `gemeente`
- **WHEN** she opens portaliq's app page
- **THEN** she is redirected to `/portal/api/session/nextcloud?portal=gemeente`

#### Scenario: Staff keep the app
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** a clerk outside every resident group, or an administrator who is also in `inwoners`
- **WHEN** they open portaliq's app page
- **THEN** the app page renders
