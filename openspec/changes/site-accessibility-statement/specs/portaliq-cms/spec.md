## ADDED Requirements

### Requirement: The product measures its own pages on this instance (REQ-SAS-001)

The portal admin SHALL offer "Measure accessibility". It SHALL load the portal's home page, search
page, one publication page, the not-found page and every page the administrator added to the list,
each in a frame in the administrator's browser, run axe-core with the tags wcag2a, wcag2aa, wcag21a,
wcag21aa and wcag22aa on the site root, and post the result to POST
`/api/portals/{portal}/accessibility/measurements`. The server SHALL store an
`accessibilityMeasurement` with `portal`, `measuredAt`, `measuredBy`, `axeVersion`, `tags`, `theme`,
and per page `{url, measured: bool, reason?, violations: list<{rule, impact, nodes, helpUrl}>}`. A
page that could not be loaded or framed SHALL be stored with `measured: false` and the reason, and
SHALL NOT count as passing. axe-core SHALL load as a lazy admin chunk and SHALL NOT enter the site
entry.

#### Scenario: A run is stored with its evidence
- **GIVEN** an administrator on a portal using theme "vng"
- **WHEN** they run "Measure accessibility"
- **THEN** one measurement SHALL be stored with the axe version, the five tags, theme "vng" and a row per page

#### Scenario: A page that cannot be framed is not a pass
- **GIVEN** a page that refuses to be framed
- **WHEN** the measurement runs
- **THEN** that page SHALL be stored with `measured: false` and the reason, and the statement SHALL list it as not measured

### Requirement: Each portal publishes a statement in the national model (REQ-SAS-002)

Each portal SHALL serve a public page at `/toegankelijkheid`, linked from the site footer, with the
sections of the national model: the organisation and the website, the status, the evidence, the
known issues, and how to report a barrier. The known issues SHALL be generated from the latest
measurement: per violated rule, a plain-language sentence, the impact and the number of pages. The
page SHALL show the date of the measurement it rests on and SHALL be regenerated when a new
measurement is stored. When the administrator recorded the register URL, the page SHALL link to it.

#### Scenario: The statement follows the measurement
- **GIVEN** a measurement with a `color-contrast` violation of impact serious on two pages
- **WHEN** an anonymous visitor opens `/toegankelijkheid`
- **THEN** the known issues SHALL name insufficient colour contrast, serious, on two pages, and the measurement date

#### Scenario: A newer measurement updates it
- **GIVEN** the statement above
- **WHEN** a new measurement without violations is stored
- **THEN** the statement SHALL no longer list the contrast issue and SHALL show the new date

### Requirement: The status never goes beyond the evidence (REQ-SAS-003)

The statement's status SHALL be A or B only when the administrator recorded an audit with party,
date, report link and result, and the status SHALL then be the audit's result. Without an audit, the
status SHALL be at most C, and the statement SHALL say that the product's automated measurement does
not establish compliance. An audit older than three years SHALL NOT support A or B, and the admin
SHALL say so.

#### Scenario: No audit, no full compliance claim
- **GIVEN** a measurement with no violations and no audit recorded
- **WHEN** the statement is generated
- **THEN** the status SHALL NOT be A or B, and the statement SHALL say an automated check does not establish compliance

#### Scenario: An audit supports B
- **GIVEN** an audit recorded on 2026-06-01 by an independent party with result B and a report link
- **WHEN** the statement is generated
- **THEN** the status SHALL be B and the evidence SHALL name the party, the date and the report
