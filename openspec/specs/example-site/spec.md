# example-site Specification

## Purpose
The Zuiddrecht example site: its organisation and fictitious contact details, and the public pages it ships, such as the software catalogue, the service status and the organisation registration form.

## Requirements

### Requirement: The Zuiddrecht example site names its organisation and fictitious contact details
The shipped Zuiddrecht site MUST declare `organisation: zuiddrecht`, the slug of the OpenRegister organisation the Zuiddrecht example set creates and the organisation of the portal accounts it writes. Its texts MUST NOT hold the placeholders `[telefoonnummer]`, `[e-mailadres]` or `[adres]`; contact details MUST be fictitious (an e-mail address on `zuiddrecht.example`, a phone number marked as an example in the footer), except that a text about direct danger MUST name 112.

#### Scenario: The shipped site
@e2e exclude Node: tests/example-site.spec.mjs
- GIVEN `lib/Settings/sites/zuiddrecht.json`
- WHEN it is read
- THEN its portal names organisation `zuiddrecht` and no text holds a bracketed placeholder

### Requirement: The example site shows stackiq's software catalogue to anyone

The Zuiddrecht example site SHALL have a page `/softwarecatalogus` that places the public records block on stackiq's `softwareCatalogue` list with a search label, and the page SHALL require the app `stackiq`, so it is left out where stackiq is not installed.

#### Scenario: A visitor searches the software catalogue without signing in
@e2e exclude The page is configuration over the public records block, which portaliq's own e2e covers; tests/site-software-catalogue.spec.mjs asserts the placement and tests/example-site.spec.mjs the props.

- **GIVEN** an instance with stackiq installed and published applications
- **WHEN** a visitor opens /softwarecatalogus on the Zuiddrecht site
- **THEN** the page lists the published applications with supplier and licence
- **AND** choosing one opens its record with its reference components

#### Scenario: Without stackiq the page is not installed

- **GIVEN** an instance without stackiq
- **WHEN** the example site is installed
- **THEN** it has no /softwarecatalogus page
- @e2e exclude ExampleSiteCatalogue drops a page whose requiresApp is not installed; covered by its PHPUnit suite.

### Requirement: The example site shows the status of the services

The Zuiddrecht example site SHALL have a published `/status` page, needing
opencatalogi, whose public table reads opencatalogi's `serviceStatus` kind with
the columns Dienst, Toelichting and Status, and the footer menu "Over deze
website" SHALL link to it.

#### Scenario: A visitor checks whether the Woo request form works

- **GIVEN** opencatalogi reports "Woo-verzoek indienen" as Verstoring
- **WHEN** a visitor opens /status
- **THEN** the table lists "Woo-verzoek indienen" with its explanation and "Verstoring"

### Requirement: An example site can ship a form with the page that shows it

An example site declaration MAY list `forms`, each a `form` object keyed by its `externalReference`. The installer SHALL create the forms for the site's portal before its pages, keep a form whose key is already installed, and write into each page's `form` block that names a `formKey` the installed form's id as `formId`. A form that requires an app SHALL be left out where that app is not installed.

#### Scenario: The registration form arrives with the catalogue page
@e2e exclude An install-time mapping; tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php asserts the form is created first and the page block carries its id.

- **GIVEN** the Zuiddrecht example site declaring the form with externalReference `organisation-registration` and a page whose form block names `formKey: organisation-registration`
- **WHEN** an administrator installs the example site
- **THEN** the form exists for the Zuiddrecht portal and the page's form block carries its id as `formId`

### Requirement: The example site lets an organisation register from the software catalogue

The Zuiddrecht site's `/softwarecatalogus` page SHALL show a registration form whose destination is stackiq's organisation schema with `registrationStatus` `pending` fixed, asking the organisation's name, type, OIN, website and the contact's name and e-mail address. Page, form and link SHALL require stackiq.

#### Scenario: A supplier registers without an account
@e2e exclude Writes into another app's register on the shared instance; tests/site-software-catalogue.spec.mjs asserts the placement and the destination, stackiq's AnonymousRegistrationListenerTest the stored status.

- **GIVEN** a visitor on /softwarecatalogus who is not signed in
- **WHEN** they fill in "Open Kaart BV", choose Leverancier and send the form
- **THEN** stackiq stores the organisation with `registrationStatus` `pending`

### Requirement: An administrator must be able to install an example site with one command

`occ portaliq:example-site:install <site>` MUST write the portal, menus, pages and news items of
the named declaration (`lib/Settings/sites/<site>.json`) into the portaliq register. It MUST write
only what is missing: a portal found by its slug, a menu found by position and title, a page found
by its route and a news item found by its title are left exactly as they are. It MUST record the
id of every object it creates. An unknown site MUST end with exit code 1 and the list of sites
that exist.

#### Scenario: A fresh instance
@e2e exclude Needs a Nextcloud with OpenRegister; covered by PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php and the coordinator's live check
- GIVEN an instance without a portal `zuiddrecht`
- WHEN an administrator runs `occ portaliq:example-site:install zuiddrecht`
- THEN the portal, 3 menus, 33 pages and 4 news items exist, and the command exits 0

#### Scenario: A second run
@e2e exclude PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php
- GIVEN the site was installed and an editor changed the home page
- WHEN the command runs again
- THEN nothing is written and the changed home page is as the editor left it

#### Scenario: An unknown site
@e2e exclude PHPUnit tests/Unit/Command/ExampleSiteCommandsTest.php
- GIVEN no declaration named `nergens`
- WHEN the command runs with `nergens`
- THEN it exits 1 and names `zuiddrecht`

### Requirement: The install must prove what arrived

After writing, the install MUST read the instance back and compare it with the declaration: per
type the number of declared objects found, and per object every declared key, at any depth, that
the stored object does not hold with the declared value. A missing object or a lost key MUST be
named in the output and MUST end the command with exit code 2. The answer of the write itself MUST
NOT be used as proof.

#### Scenario: The register drops a key
@e2e exclude PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php
- GIVEN a register whose portal schema does not know `headerSearch`
- WHEN the site is installed
- THEN the report names `portal zuiddrecht: headerSearch.label` among the lost keys and the command exits 2

#### Scenario: A page does not arrive
@e2e exclude PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php
- GIVEN a store that refuses one page
- WHEN the site is installed
- THEN the report counts 32 of 33 pages and names the route that is missing

### Requirement: An administrator must be able to remove an example site

`occ portaliq:example-site:remove <site>` MUST delete the objects the install recorded and no
others. The portal MUST stay when the install did not create it, or when it still holds a menu or
a page after the recorded ones are gone. The record MUST be cleared afterwards, so a new install
starts clean.

#### Scenario: Remove after install
@e2e exclude PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php
- GIVEN the site was installed on a fresh instance
- WHEN the administrator runs the remove command
- THEN the portal, its menus, pages and news items are gone

#### Scenario: Content of the organisation's own
@e2e exclude PHPUnit tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php
- GIVEN an editor added a page to the installed portal
- WHEN the administrator runs the remove command
- THEN that page and the portal stay, and the output says why the portal was kept

### Requirement: A shipped declaration must fit the schemas and the blocks it names

Every shipped declaration MUST hold only keys the `portal`, `menu`, `page` and `newsItem` schemas
of `lib/Settings/portaliq_register.json` declare, with every required key and only allowed enum
values. Every widget on a page MUST be one the public site renders, with only props that widget
takes. Every link to a page of the site, in a menu, a block or the portal record, MUST lead to a
declared page or to the resident's own area. No text MUST hold an em-dash.

#### Scenario: The Zuiddrecht declaration
@e2e exclude Checked in node: tests/example-site.spec.mjs, in check:specs
- GIVEN `lib/Settings/sites/zuiddrecht.json`
- WHEN the check runs
- THEN no unknown key, unknown widget, unknown prop or dead link is found

### Requirement: The current menu item may sit in the line under the menu

When the theme sets `--nldesign-website-nav-current-in-line` to 1, the bar of the current menu
item MUST be drawn in the line under the menu bar, as high as that line, in
`--nldesign-website-nav-current-color`. Without these tokens the bar MUST stay where and as it
was.

#### Scenario: Zuiddrecht
@e2e exclude CSS contract in tests/example-site.spec.mjs; live screenshot by the coordinator
- GIVEN a portal on a set with a 5px red line and the two tokens (blue, in the line)
- WHEN the page for "Afval" is open
- THEN a blue piece of 5px sits in the red line under "Afval", and the item has no bar above the line

### Requirement: A link in a link list or a button link must open the page wherever the site is served

`nlLinkList` and `nlButtonLink` MUST turn an authored path inside the site into the site's own
address for that route, and a plain click on it MUST stay in the site. Web, mail and phone
addresses MUST pass unchanged; anything else MUST NOT become a link.

#### Scenario: A portal served through Nextcloud
@e2e exclude Rendered in node: tests/example-site.spec.mjs
- GIVEN the site at `/apps/portaliq/site?portal=zuiddrecht`
- WHEN a link list holds `/afval`
- THEN the link's address keeps `portal=zuiddrecht` and names the route `/afval`

### Requirement: The own area must carry the name the portal gives it

A portal that sets `accountLabel` MUST show that name wherever the site names the resident's own
area: the breadcrumb, the resident menu, the link in the header and the tab title. A portal
without one MUST keep "Mijn omgeving".

#### Scenario: Mijn Zuiddrecht
@e2e exclude Checked in node: tests/example-site.spec.mjs; rendered signed out in the lane's preview
- GIVEN a portal with `accountLabel` "Mijn Zuiddrecht"
- WHEN a visitor opens `/mijn`
- THEN the breadcrumb reads "Home", "Mijn Zuiddrecht"

### Requirement: The Zuiddrecht site must offer its two ways in as the design shows

The Zuiddrecht declaration MUST hold a sign-in card for each way in it names besides `public`:
DigiD for a resident, eHerkenning for a company, each with the title, text and button of the
Inloggen board, and the page's heading, introduction and notice.

#### Scenario: The sign-in page
@e2e exclude Checked in node: tests/example-site.spec.mjs; rendered in the lane's preview through the real shell projection
- GIVEN the installed site
- WHEN a visitor opens `/mijn` signed out
- THEN the page reads "Inloggen op Mijn Zuiddrecht" with the cards "Als inwoner" and "Namens een bedrijf"
