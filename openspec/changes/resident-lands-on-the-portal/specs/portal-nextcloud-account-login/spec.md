## ADDED Requirements

### Requirement: A resident's Nextcloud start page is the portal (REQ-NCL-007)

When a user is added to a Nextcloud group that a published portal offering the
`nextcloud` mode names as `authentication.registration.residentGroup`, portaliq
SHALL set that user's preference `core`/`defaultapp` to `portaliq`, so
Nextcloud's default page, where the registration app sends a new user, is
portaliq's app page and REQ-NCL-006 sends the resident on to their portal. It
SHALL NOT change a preference the user already has, and SHALL NOT touch an
administrator. When the user is removed from such a group, portaliq SHALL delete
the preference only when it is still `portaliq` and the user is a member of no
portal's resident group. A failure to read the portals SHALL be logged and SHALL
NOT stop the membership change.

#### Scenario: Back from registration
@e2e exclude PHPUnit: tests/Unit/Listener/ResidentLandingListenerTest.php; the live hop needs the tenant with the registration app and a mail server
- **GIVEN** portal `zuiddrecht` offers `nextcloud` and names `inwoners`, and the registration app puts new users in `inwoners`
- **WHEN** the registration app adds the new user `sanne` to `inwoners`
- **THEN** `sanne`'s `core`/`defaultapp` is `portaliq`

#### Scenario: Staff and own choices are left alone
@e2e exclude PHPUnit: tests/Unit/Listener/ResidentLandingListenerTest.php
- **GIVEN** a user added to a group no portal names, an administrator added to `inwoners`, and a resident who chose `files` as start page
- **WHEN** the memberships are added
- **THEN** no preference is written or changed

#### Scenario: Leaving the resident group
@e2e exclude PHPUnit: tests/Unit/Listener/ResidentLandingListenerTest.php
- **GIVEN** `sanne` starts on `portaliq` and `linda` chose `files`
- **WHEN** both leave `inwoners`
- **THEN** `sanne`'s preference is gone and `linda`'s is still `files`
