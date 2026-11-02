## ADDED Requirements

### Requirement: A demo may sign in each example resident with one click
A fragment's `exampleResident` MAY be one entry or a list of entries, each naming the portal, the cast key, the key of the resident's portal account object and a card (`title`, `text`, `button`). A resident MUST count only when OpenRegister's install record of the set holds its portal account under `portaliq/<key>` and lists the account's `subjectRef` as created or adopted; one that fails MUST be left out and the others MUST stand. While the app config `example_resident_demo_login` is `yes` (system `debug` MUST NOT open it), the shell's sign-in config MUST carry the set id as `exampleResident` and per resident its key, its name and its card as `exampleResidents`, never an account id. The site MUST draw one card per resident after the portal's own ways in, linking to `GET /portal/api/session/example-resident?id=<set>&resident=<key>&portal=<slug>`, and MUST say "Alleen op deze demo" under its button. The cards MUST NOT replace or hide any way in the portal declares: the `nextcloud` way MUST stay the account route with its "Wachtwoord vergeten" and "Account aanmaken" links. The route MUST mint a session at trust `low` for the account id the record vouches for, for the named resident, or the set's first resident of the portal when no resident is named, and only when the portal offers the `nextcloud` mode and the resident's portal account is active. Every refusal MUST be a throttled 404. With the switch off there MUST be no card and the route MUST answer 404.

#### Scenario: Linda with one click
@e2e exclude Unit: tests/Unit/Controller/ExampleResidentControllerTest.php; the live click needs an installed set
- GIVEN the switch is on and set `zuiddrecht` installed Linda's portal account for `linda.bakker`
- WHEN the route is called with `id=zuiddrecht&resident=linda&portal=zuiddrecht`
- THEN a session MUST be issued for `linda.bakker` at trust `low`

#### Scenario: A resident the record does not vouch for
@e2e exclude Unit: tests/Unit/Service/ExampleResident/ExampleSetResidentsTest.php
- GIVEN Henk's recorded portal account was edited to name an account the set did not make
- WHEN portaliq lists the example residents of the portal
- THEN Henk MUST be left out and Sanne, Linda and Fatima MUST still be listed

#### Scenario: The cards sit beside the account card
@e2e exclude Node: tests/site-auth.spec.mjs and tests/nextcloud-registration-link.spec.mjs
- GIVEN a portal offering DigiD and `nextcloud`, with a registration address, and the switch on with four residents
- WHEN the sign-in routes are built
- THEN the `nextcloud` route MUST lead to the account sign-in and carry the registration address
- AND four demo cards MUST follow it, each with its own key and `resident` in its link

#### Scenario: The switch is off
@e2e exclude Unit: tests/Unit/Service/ExampleResident/ExampleResidentSignInTest.php
- GIVEN the residents are installed and `example_resident_demo_login` is not `yes`
- WHEN the sign-in config is served and the route is called
- THEN `exampleResidents` MUST be empty, the `nextcloud` way MUST still be offered, and the route MUST answer 404

### Requirement: The example residents are in the portal's resident group
portaliq's Zuiddrecht fragment MUST declare the group `inwoners` with the residents Sanne, Henk, Linda and Fatima as members, and its change on the portal MUST set `authentication.registration.residentGroup` to `inwoners` with the `nextcloud` way in. Business contacts MUST NOT be members: they act for a company, and their portal accounts have audience `supplier`.

#### Scenario: The residents' group
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its groups and changes are read
- THEN `inwoners` MUST hold exactly `sanne`, `henk`, `linda` and `fatima`, and the change MUST name `inwoners` as the resident group

## MODIFIED Requirements

### Requirement: The set's other residents and business contacts have a portal account
portaliq's fragment MUST give the cast members whose dossiq cases the set writes a portal account under their own account id: residents `henk`, `linda` and `fatima` (audience `citizen`), and business contacts `janwillem` and `mark` (audience `supplier`). It MUST record the mandates that open cases they did not file: Linda for her father Henk (`subject:` Henk's account id), Jan-Willem for Slagerij Van der Berg and Mark for CloudGemeente B.V. (`kvk:` and the KVK number). They sign in with their own Nextcloud account and the `nextcloud` mode. The residents Sanne, Henk, Linda and Fatima MUST each be an entry of `exampleResident`, and exactly those accounts MUST carry `identityRef: example-resident`.

#### Scenario: Linda sees her father's case
@e2e exclude Live: needs dossiq #3872 and an installed set; the fragment is checked by tests/example-resident.spec.mjs
- GIVEN the set is installed with dossiq's fragment
- WHEN Linda signs in
- THEN Henk's case with Linda as authorised representative MUST be listed, named as granted by the mandate "Namens Henk Bakker"

#### Scenario: The residents are one click away, the business contacts are not
@e2e exclude Node: tests/example-resident.spec.mjs
- GIVEN the shipped `zuiddrecht.json`
- WHEN its portal accounts are read
- THEN the accounts of Sanne, Henk, Linda and Fatima MUST be the example residents, and Jan-Willem's and Mark's MUST NOT

## REMOVED Requirements

### Requirement: A demo may sign the example resident in with one click
**Reason**: The single card replaced the `nextcloud` way in, dropped "Account aanmaken" on a demo, and hid the way in while the switch was off.
**Migration**: "A demo may sign in each example resident with one click" covers the route and the switch; the `nextcloud` way stays the account route.
