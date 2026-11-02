## MODIFIED Requirements

### Requirement: The sign-in page leads to Nextcloud's own registration (REQ-NCL-005)

For a portal that offers the `nextcloud` mode and declares
`authentication.registration.residentGroup`, the site config SHALL carry
`registerUrl`, the address of the Nextcloud registration app's e-mail form, when
that app is enabled and its `registered_user_group` setting names exactly the
declared group. Otherwise `registerUrl` SHALL be `''`. The address SHALL be built
on the server from the route name and SHALL NOT be taken from the request. The
sign-in page SHALL show "Account aanmaken" on the account route's card only when
`registerUrl` is set, also while one-click demo cards are shown beside it. A portal
whose only e-mail way in is the e-mail link SHALL NOT get the link (REQ-IWI-014).
Portaliq SHALL NOT receive, store or check a password.

#### Scenario: A portal with residents
@e2e exclude PHPUnit and node: tests/Unit/Controller/ContentControllerTest.php and tests/nextcloud-registration-link.spec.mjs; the click-through needs a tenant with the registration app
- **GIVEN** a portal declaring `nextcloud` and `registration.residentGroup: inwoners`, and the registration app enabled with `registered_user_group` `inwoners`
- **WHEN** a visitor opens the sign-in page
- **THEN** the account route's card shows "Account aanmaken" leading to the registration app's form

#### Scenario: On a demo
@e2e exclude Node: tests/nextcloud-registration-link.spec.mjs
- **GIVEN** the same portal with the one-click demo switched on
- **WHEN** the sign-in routes are built
- **THEN** the account route still carries the registration address and leads to the account sign-in, and the demo cards follow it

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
