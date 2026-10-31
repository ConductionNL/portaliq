## ADDED Requirements

### Requirement: A portal may name a business group with its own audience (REQ-NCL-008)

A portal offering the `nextcloud` mode MAY name
`authentication.registration.businessGroup`, the Nextcloud group whose members
act for a company, and `authentication.registration.businessAudience`, the
audience their accounts get (empty means `supplier`). Portaliq SHALL treat a
member of the business group as it treats a member of the resident group, with
the business audience: a first portal account on sign-in through the account
route (REQ-NCL-003), the way back from the workplace (REQ-NCL-006) and the start
page on joining the group (REQ-NCL-007). The business group SHALL NOT open the
"Create an account" link (REQ-NCL-005), which follows the resident group only. A
group named as both SHALL count as the resident group. A portal without the
`nextcloud` mode SHALL have no business group, whatever it names. No code SHALL
name the group: it is configuration.

#### Scenario: A business contact signs in for the first time
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php; the live sign-in needs the tenant with the set installed
- **GIVEN** a portal offering `nextcloud` that names `inwoners` and `ondernemers`, and a Nextcloud user `karin` in `ondernemers` without a portal account
- **WHEN** `karin` signs in through the account route
- **THEN** an active portal account with audience `supplier` is written for `karin` and a session with that audience is issued
- **AND** on a portal that names no business group, `karin` is refused and nothing is written

#### Scenario: A business contact on the workplace
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** `karin` is in `ondernemers`, which a published portal names as its business group
- **WHEN** `karin` opens portaliq's app page
- **THEN** she is sent to that portal's account route

#### Scenario: Joining and leaving the business group
@e2e exclude PHPUnit: tests/Unit/Listener/ResidentLandingListenerTest.php
- **GIVEN** portal `zuiddrecht` offers `nextcloud` and names `ondernemers` as its business group
- **WHEN** `karin` is added to `ondernemers`, and later removed
- **THEN** her `core`/`defaultapp` is `portaliq`, and after the removal it is gone unless she is still in a group a portal names

#### Scenario: The business group opens no registration link
@e2e exclude PHPUnit: tests/Unit/Service/Identity/NextcloudAccountRegistrationTest.php
- **GIVEN** the registration app puts new users in `ondernemers`, the portal's business group
- **WHEN** the sign-in page is built
- **THEN** there is no "Create an account" link
