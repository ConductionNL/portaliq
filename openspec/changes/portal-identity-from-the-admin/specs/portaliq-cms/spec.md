## ADDED Requirements

### Requirement: The portal's favicon, logo and hero image come from the media library (REQ-PIA-001)

`#portal` SHALL declare `favicon` and `heroImage` as references to a `media` object of the same
portal, and `logo` SHALL accept a `media` reference as well as the existing URL. The portal settings
form SHALL let an administrator pick each from the media library or upload an image into it. A
favicon SHALL be a PNG, SVG or ICO; anything else SHALL be refused with the accepted types named. A
media reference that belongs to another portal SHALL be refused.

#### Scenario: An administrator sets the three images
- **GIVEN** an administrator on the portal settings form
- **WHEN** they upload a favicon, pick a logo and pick a hero image from the media library, and save
- **THEN** `#portal.favicon`, `#portal.logo` and `#portal.heroImage` SHALL each reference a `media` object of that portal

#### Scenario: A JPEG is not a favicon
- **GIVEN** the favicon picker
- **WHEN** the administrator uploads a JPEG
- **THEN** it SHALL be refused, naming PNG, SVG and ICO

### Requirement: The site head and the hero use the portal's images (REQ-PIA-002)

`templates/site.php` SHALL emit `<link rel="icon">` from `#portal.favicon`, then `#portal.logo`,
then the theme's icon, then portaliq's own mark, the first that resolves. The URL SHALL be a public
media URL that answers without a session. The hero block SHALL use `#portal.heroImage` when its own
background image is empty.

#### Scenario: The favicon is the portal's own
- **GIVEN** a portal with a favicon and a different logo
- **WHEN** an anonymous visitor loads any site page
- **THEN** the head SHALL carry one `<link rel="icon">` pointing at the favicon's public URL, which answers 200 without a session

#### Scenario: A hero without its own image uses the portal's
- **GIVEN** a home page hero block with no background image, and a portal hero image
- **WHEN** the page renders
- **THEN** the hero SHALL show the portal's hero image

### Requirement: The portal names its organisation type from TOOI (REQ-PIA-003)

`#portal` SHALL declare `organisationType` (a TOOI organisation type URI) and
`organisationTypeLabel`. The settings form SHALL offer the concepts of the TOOI organisation type
scheme from OpenRegister's concept register and SHALL store the URI and the label picked. The scheme
is the app setting `organisation_type_scheme`, by default TOOI-kern
`https://identifier.overheid.nl/tooi/def/thes/kern/overheidsorganisatie`; the concepts under
ambtsdrager, functionaris and organisatieonderdeel (found through `skos:broader`) are not organisation
types and SHALL NOT be offered. When the
scheme is not in the concept register, the picker SHALL say so and offer nothing, and no free text
SHALL be accepted. The site SHALL use the label wherever it names the organisation's kind: the
signed-in area's "Van ..." line (with the Dutch article of the label: "het" for waterschap and
ministerie, "de" otherwise), the footer, and `<meta name="DCTERMS.creator">`. A portal without an
organisation type SHALL say "Van de organisatie".

#### Scenario: A water authority is not called a municipality
- **GIVEN** a portal with `organisationType` the TOOI concept for waterschap and label "waterschap"
- **WHEN** a signed-in resident opens a message from the organisation
- **THEN** it SHALL read "Van het waterschap" and nowhere "Van de gemeente"

#### Scenario: Office holders are not organisation types
- **GIVEN** the default scheme holds gemeente and waterschap, and burgemeester under ambtsdrager
- **WHEN** the administrator opens the organisation type picker
- **THEN** it SHALL offer gemeente and waterschap, and SHALL NOT offer ambtsdrager or burgemeester

#### Scenario: No type set
- **GIVEN** a portal without an organisation type
- **WHEN** the same message is opened
- **THEN** it SHALL read "Van de organisatie"
