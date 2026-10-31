## ADDED Requirements

### Requirement: The example resident comes from the example set
portaliq MUST ship its part of an example set in `lib/Settings/example-sets/<set>.json`, and
that file MUST name its resident in `exampleResident`: the portal slug, the cast key, the key of
the portal account object and the key of the change that adds the sign-in mode. portaliq MUST take
the example resident of a portal from OpenRegister's install record of that set: the portal
account MUST be an object the record holds under `portaliq/<key>`, and its `subjectRef` MUST be an
account the record lists as created or adopted. A portal account that the record does not hold, or
whose account id the set did not make or adopt, MUST NOT make anyone the example resident.

#### Scenario: The installed resident of the portal
@e2e exclude Unit: tests/Unit/Service/ExampleResident/ExampleSetResidentsTest.php
- GIVEN set `zuiddrecht` installed the portal account `portaalaccount-sanne` for the account `sanne.devries` it created
- WHEN portaliq asks for the example resident of portal `zuiddrecht`
- THEN it MUST get set `zuiddrecht` and account `sanne.devries`

#### Scenario: An edited account makes nobody the resident
@e2e exclude Unit: tests/Unit/Service/ExampleResident/ExampleSetResidentsTest.php
- GIVEN the recorded portal account's `subjectRef` was changed to an account the set did not make
- WHEN portaliq asks for the example resident of the portal
- THEN there MUST be none, and the demo sign-in MUST offer nobody

#### Scenario: The set keeps the Woo journey's cases
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its objects are read
- THEN they MUST hold the five cases, "Verlichting fietspad Lindelaan" first, with the question and the four messages

### Requirement: The set's other residents and business contacts have a portal account
portaliq's fragment MUST give the cast members whose dossiq cases the set writes a portal account under their own account id: residents `henk`, `linda` and `fatima` (audience `citizen`), and business contacts `janwillem` and `mark` (audience `supplier`). It MUST record the mandates that open cases they did not file: Linda for her father Henk (`subject:` Henk's account id), Jan-Willem for Slagerij Van der Berg and Mark for CloudGemeente B.V. (`kvk:` and the KVK number). They sign in with their own Nextcloud account and the `nextcloud` mode. The one-click demo sign-in MUST stay for Sanne alone: no other account carries `identityRef: example-resident`.

#### Scenario: Linda sees her father's case
@e2e exclude Live: needs dossiq #3872 and an installed set; the fragment is checked by tests/example-resident.spec.mjs
- GIVEN the set is installed with dossiq's fragment
- WHEN Linda signs in
- THEN Henk's case with Linda as authorised representative MUST be listed, named as granted by the mandate "Namens Henk Bakker"

#### Scenario: Only Sanne is one click away
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its portal accounts are read
- THEN exactly one, Sanne's, MUST be the example resident

### Requirement: The old install can be removed for one release
`occ portaliq:example-resident:remove <site>` MUST remain for one release after the install
command retired, MUST delete only what the old install recorded, MUST take the portal from
`--portal` (default the site id), and MUST name `occ openregister:example-set:install <site>` as
the way to install the resident again.

#### Scenario: Remove points at the loader
@e2e exclude Unit: tests/Unit/Command/ExampleResidentCommandsTest.php
- WHEN the remove command runs, with or without a record
- THEN its output MUST name `occ openregister:example-set:install zuiddrecht`

## MODIFIED Requirements

### Requirement: A demo may sign the example resident in with one click
`GET /portal/api/session/example-resident?id=<set>&portal=<slug>` MUST mint a portal
session for the example resident's own Nextcloud account id and hand the bearer back in the URL
fragment, as the `nextcloud` mode does, only when ALL of these hold: the app config
`example_resident_demo_login` is `yes` (system `debug` MUST NOT open it), example set `<set>`
installed the resident of the named portal (see "The example resident comes from the example
set"), the portal offers the `nextcloud` mode, and the resident's portal account is active. The
subject MUST be the account id the set's install record vouches for, never a value the caller
supplies. Every refusal MUST be a 404 marked for the bruteforce throttler, and the route MUST
carry the same anonymous rate limit as `dev-login`. While the switch is on, the shell's sign-in
config MUST carry the set id as `exampleResident`, and the site MUST link the resident's card to
this route and say "Alleen op deze demo" under its button; with the switch off the card MUST keep
the Nextcloud-account path, and the site MUST leave the card out when the set added the mode.
Signing out MUST end the portal session as for any session.

#### Scenario: The switch is off
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the example resident is installed and `example_resident_demo_login` is not `yes`
- WHEN the route is called
- THEN it MUST answer 404 and mint nothing, even with `debug` on

#### Scenario: Another set or another portal
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the switch is on
- WHEN the route is called with a set that did not install the portal's resident, or with a portal that is not the resident's
- THEN it MUST answer 404 and mint nothing

#### Scenario: One click
@e2e exclude Unit: tests/Unit/Controller/SessionControllerTest.php
- GIVEN the switch is on, set `zuiddrecht` installed the resident as `sanne.devries` and its portal account is active
- WHEN the route is called for its portal
- THEN a session MUST be issued for `sanne.devries` at trust `low`
- AND the browser MUST be sent to the site with the bearer in the fragment
