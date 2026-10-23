## ADDED Requirements

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
