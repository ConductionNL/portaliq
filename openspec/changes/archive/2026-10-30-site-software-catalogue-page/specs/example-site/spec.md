## ADDED Requirements

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
