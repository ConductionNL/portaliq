## ADDED Requirements

### Requirement: An administrator must be able to install an example resident with one command

`occ portaliq:example-resident:install <site>` MUST make a Nextcloud account for the resident of
the named declaration (`lib/Settings/sites/residents/<site>.json`), a portal account under the
same id, and MUST write the declared objects in order, each into the register and schema it
names, with every placeholder filled: the resident's reference, the id a lookup finds, a field of
an object written earlier, and dates counted from the day of the install. It MUST write only what
its own record lacks. It MUST stop before the first write, with exit code 1, when OpenRegister or
the example site is missing, or when the account id belongs to a Nextcloud account or a portal
account the command did not make. An object this instance cannot take MUST be named with the
reason, and so MUST every later object that quotes it.

#### Scenario: A fresh instance with the site and dossiq
@e2e exclude Needs a Nextcloud with OpenRegister and dossiq; covered by PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php and the coordinator's live check
- GIVEN the Zuiddrecht site is installed and dossiq ships its case types
- WHEN an administrator runs `occ portaliq:example-resident:install zuiddrecht`
- THEN the account, the portal account, 5 cases, 1 question and 4 messages exist, the password is shown once, and the command exits 0

#### Scenario: A second run
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN the resident was installed
- WHEN the command runs again
- THEN nothing is written, every part reads "already there" and no password is shown

#### Scenario: An account that is somebody's
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN a Nextcloud account `sanne.devries` the command did not make
- WHEN the command runs
- THEN nothing is written, the output names `--user`, and with `--user demo-inwoner` every case is written for that id

#### Scenario: Without the case app
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN an instance without dossiq
- WHEN the command runs
- THEN the resident can sign in, every case, question and message is named as not written with the reason, and the command exits 2

### Requirement: The install must prove what arrived

After writing, the install MUST read the instance back and compare it with the declaration: per
register and schema the number of declared objects found, and per object of this run every
declared key, at any depth, that the stored object does not hold with the declared value. A key
kept as JSON text MUST be compared as the list it holds. The portal account and the sign-in mode
MUST be read back the same way. A missing object, a lost key or an object that could not be
written MUST end the command with exit code 2.

#### Scenario: The register drops a key
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN a register whose case schema does not keep `waitingOnApplicant`
- WHEN the resident is installed
- THEN the report names `dossiq case vergunning-dakkapel: waitingOnApplicant` among the lost keys and the command exits 2

#### Scenario: A case type is not there
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN an instance without the case type `woo-verzoek`
- WHEN the resident is installed
- THEN the report names the case with what was looked for and the message that quotes it, and counts 4 of 5 cases

### Requirement: The resident must sign in without a test door

The install MUST add the sign-in mode the declaration names to the portal, with its card when the
portal has none for that mode, and MUST leave everything else on the portal as it is. With mode
`nextcloud` the resident signs in on Nextcloud's own form; a session MUST be minted only for a
Nextcloud account that has an active portal account under the same id. The install MUST NOT open
the test sign-in (`dev_login_enabled`), and the documentation MUST say what that door is, that
`debug` mode opens it too, and why it stays closed.

#### Scenario: The sign-in page
@e2e exclude Live check by the coordinator (shots/pr1-1-inloggen.png); the portal row in PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN the resident is installed
- WHEN a visitor presses "Mijn Zuiddrecht"
- THEN the page shows the DigiD and eHerkenning cards as before and a third card "Voorbeeldinwoner", and the button opens Nextcloud's sign-in form

#### Scenario: Another Nextcloud account
@e2e exclude SessionController::nextcloud() refuses without a portal account; live check by the coordinator
- GIVEN a Nextcloud account that is not the resident
- WHEN it follows the same button
- THEN it gets `no_portal_account` and no session

### Requirement: An administrator must be able to remove an example resident

`occ portaliq:example-resident:remove <site>` MUST delete the objects the install recorded, last
written first, the portal account and the Nextcloud account the install made, and MUST take the
sign-in mode off the portal only when the install added it. An object OpenRegister will not
delete MUST be named, MUST stay recorded, and a new install for the same account id MUST use it
again instead of writing a second. Nothing the install did not make MUST be touched.

#### Scenario: Remove after install
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php
- GIVEN the resident was installed on a fresh instance
- WHEN the administrator runs the remove command
- THEN the messages, the question, the cases, both accounts and the mode are gone, and the portal is as it was

#### Scenario: A case the register keeps
@e2e exclude PHPUnit tests/Unit/Service/ExampleResident/ExampleResidentInstallerTest.php; live on an instance with dossiq (a case is an archive record)
- GIVEN the register refuses to delete a case
- WHEN the administrator runs the remove command and then installs again
- THEN the command names the five cases and exits 2, the accounts are gone, and the new install counts 5 cases already there and writes none

### Requirement: A shipped declaration must fit what fills it

Every shipped declaration MUST name a shipped example site, a resident with an account id, a name,
an audience dossiq serves and an organisation, and a sign-in mode the portal schema allows. The
portal account it writes MUST fit the `portalAccount` schema. Every placeholder MUST be a form the
install fills, every lookup MUST be declared on the object that uses it, and an object MUST only
quote objects declared before it. No text a resident reads MUST hold an em-dash.

#### Scenario: The Zuiddrecht declaration
@e2e exclude Checked in node: tests/example-resident.spec.mjs, in check:specs
- GIVEN `lib/Settings/sites/residents/zuiddrecht.json`
- WHEN the check runs
- THEN no unknown form, undeclared lookup, forward reference or em-dash is found
