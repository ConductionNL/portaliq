## ADDED Requirements

### Requirement: A page can lock a filter on the search block (REQ-HTL-001)

`FederatedSearchBlock` SHALL accept a `lockedFilters` prop, a map of facet field to value.
`buildRequestUrl()` SHALL send every locked filter on every request, whatever the visitor's own
filters. The block SHALL show each locked filter as a chip without a remove control, SHALL leave its
field out of the facet list, and SHALL leave it out of the address bar state, so a shared link cannot
unlock it.

#### Scenario: A theme page shows only its own publications
- **GIVEN** a search block with `lockedFilters` `{themes: 'th-parkeren'}`
- **WHEN** a visitor searches "vergunning" and picks a category
- **THEN** every request SHALL carry `themes=th-parkeren`, the term and the category
- **AND** the themes facet SHALL NOT be offered

### Requirement: A subject has a public landing page (REQ-HTL-002)

The site SHALL serve `/onderwerp/{slug}`. It SHALL read the subject from
`GET /index.php/apps/opencatalogi/api/themes/{slug}` and render its image (with its alt text), title
and description, followed by the search block with `lockedFilters` `{themes: <subject id>}`. When the
subject is unknown or not public, the route SHALL render the site's not-found page with status 404.

#### Scenario: The Parkeren page
- **GIVEN** a public subject "Parkeren" with slug `parkeren`, an image and a description, and two public publications under it
- **WHEN** an anonymous visitor opens `/onderwerp/parkeren`
- **THEN** the page SHALL show the image, "Parkeren", the description and those two publications

#### Scenario: An unknown subject
- **GIVEN** no subject with slug `bestaat-niet`
- **WHEN** a visitor opens `/onderwerp/bestaat-niet`
- **THEN** the site's not-found page SHALL render with status 404

### Requirement: The home page features subjects from the register (REQ-HTL-003)

A `featuredSubjects` widget SHALL list the rows of `GET /api/themes?featured=true` whose `featured`
is true, in `featuredOrder` then `title`, each with its image, title, summary and
`publicationCount`, linking to `/onderwerp/{slug}`. It SHALL show at most the number the author sets,
default six.

#### Scenario: An administrator features a subject and it appears
- **GIVEN** an officer marks "Parkeren" featured with order 1 in opencatalogi
- **WHEN** the home page with a `featuredSubjects` widget is opened
- **THEN** "Parkeren" SHALL be listed first, linking to `/onderwerp/parkeren`

### Requirement: The portal shows live counts, as an anonymous visitor would see them (REQ-HTL-004)

A `portalCounts` widget SHALL show totals per category, per subject or per year (the author picks
one), read from the facet counts of one search request, and each count SHALL link to the search page
with that filter set. The request SHALL be made without the viewer's session (`credentials: 'omit'`),
so a signed-in officer viewing the site sees the counts the public sees. A count the endpoint did not
answer SHALL be left out, never shown as 0.

#### Scenario: Counts link into the search
- **GIVEN** 40 public publications, 12 in category "Raadsstukken"
- **WHEN** the home page with a `portalCounts` widget per category is opened
- **THEN** it SHALL show "Raadsstukken 12" linking to the search with that category set

#### Scenario: An officer sees the public count
- **GIVEN** the same portal and 5 draft publications in "Raadsstukken"
- **WHEN** a signed-in officer opens the home page
- **THEN** the widget SHALL still show 12
