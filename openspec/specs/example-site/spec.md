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
