# Spec: Example resident

## ADDED Requirements

### Requirement: A demo may sign the example resident in with one click
`GET /portal/api/session/example-resident?id=<resident>&portal=<slug>` MUST mint a portal
session for the example resident's own Nextcloud account id and hand the bearer back in the URL
fragment, as the `nextcloud` mode does, only when ALL of these hold: the app config
`example_resident_demo_login` is `yes` (system `debug` MUST NOT open it), the resident is
installed (`example_resident_<id>` names a user id), the named portal is the resident's own and
offers the `nextcloud` mode, and the resident's portal account is active. The subject MUST be the
record's user id, never a value the caller supplies. Every refusal MUST be a 404 marked for the
bruteforce throttler, and the route MUST carry the same anonymous rate limit as `dev-login`.
While the switch is on, the shell's sign-in config MUST carry the resident's id as
`exampleResident`, and the site MUST link the resident's card to this route and say "Alleen op
deze demo" under its button; with the switch off the card MUST keep the Nextcloud-account path.
Signing out MUST end the portal session as for any session.

#### Scenario: The switch is off
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the example resident is installed and `example_resident_demo_login` is not `yes`
- WHEN the route is called
- THEN it MUST answer 404 and mint nothing, even with `debug` on

#### Scenario: Another resident or another portal
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the switch is on
- WHEN the route is called with an id no install record names, or with a portal that is not the resident's
- THEN it MUST answer 404 and mint nothing

#### Scenario: One click
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the switch is on, the resident is installed as `sanne.devries` and its portal account is active
- WHEN the route is called for its portal
- THEN a session MUST be issued for `sanne.devries` at trust `low`
- AND the browser MUST be sent to the site with the bearer in the fragment
