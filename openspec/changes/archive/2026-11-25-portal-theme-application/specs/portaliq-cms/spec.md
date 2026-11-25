# portaliq-cms Delta: portal-theme-application

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-theme-application](../../)

## Purpose

Makes a website's `theme` reference change what a visitor sees. Implements
ADR-086 §§6–7, which the current renderer claims and does not do.

## ADDED Requirements

### Requirement: A site's theme MUST change its rendered appearance

The renderer SHALL load the token set for the resolved site's themiq theme.
Two sites referencing different themes SHALL render visibly differently.

#### Scenario: Two themed sites compute different colours

- **GIVEN** two websites referencing different themiq themes
- **WHEN** each is rendered and the SAME element is inspected
- **THEN** its computed colour differs between them
- **AND** the assertion is on the COMPUTED value, not on the class name, the
  presence of a token file, or the API's theme string — all three of those
  already pass while the sites render identically

#### Scenario: The token actually resolves

- **GIVEN** a rendered themed page
- **WHEN** a theme token is read from the root element
- **THEN** it has a value
- **AND** it is not the component's own hard-coded fallback

#### Scenario: Only the active theme is loaded

- **GIVEN** a site rendering under one theme
- **WHEN** the stylesheets it transferred are inspected
- **THEN** no other site's token set was fetched

### Requirement: An unresolvable theme MUST be obvious, not silently default

A site whose theme reference does not resolve SHALL report the failure and
SHALL NOT fall back to a plausible-looking default.

#### Scenario: A missing theme names itself

- **GIVEN** a website referencing a theme that does not exist
- **WHEN** it is served
- **THEN** the failure names the missing theme

#### Scenario: The unstyled state is visibly unstyled

- **GIVEN** a site whose token set fails to load
- **WHEN** it renders
- **THEN** it does not present as a correctly-branded page
- **AND** this is the point: a page that quietly falls back to reasonable
  colours is never reported, which is how the defect this change fixes
  survived being looked at

### Requirement: Markup MUST carry NL Design component classes

Rendered components SHALL emit NL Design component classes. A React
design-system package SHALL NOT be introduced.

#### Scenario: The rendered DOM carries the classes

- **GIVEN** a rendered page under an active theme
- **WHEN** its markup is inspected
- **THEN** NL Design component classes are present on the heading, links,
  paragraphs and table
- **AND** the count is greater than zero — it is currently exactly zero

#### Scenario: No React design-system dependency appears

- **GIVEN** the dependency set after this change
- **THEN** it contains no `*-react` design-system package

### Requirement: Portaliq MUST declare its dependency on themiq

`appinfo/info.xml` SHALL declare the theming app as a dependency, and a
missing install SHALL be reported rather than rendered around.

#### Scenario: The dependency is declared

- **WHEN** `appinfo/info.xml` is inspected
- **THEN** it names the theming app

#### Scenario: A missing theming app is reported

- **GIVEN** an installation without it
- **WHEN** a website is served
- **THEN** the failure names the missing dependency
