## ADDED Requirements

### Requirement: A document opens in the browser (REQ-PDC-001)

On the publication page and the document page, each document SHALL open through nextcloud-vue's
public file opener: PDF and image types in the browser's own viewer, CSV, TSV, JSON and XML in
`CnFilePreview`, and any other type as a download. A "Download" link to the unchanged file SHALL stay
beside it. A document URL that is not the opencatalogi public file URL SHALL NOT be opened.

#### Scenario: A PDF opens, it does not download
- **GIVEN** a public publication with a PDF document
- **WHEN** a visitor activates "Bekijken" on it
- **THEN** the PDF SHALL open in the browser's viewer and no download SHALL start

#### Scenario: A CSV shows as a table
- **GIVEN** a public publication with a CSV document, and nextcloud-vue with `CnFilePreview`
- **WHEN** a visitor activates "Bekijken"
- **THEN** the first rows SHALL show as a table with a Download button

### Requirement: Every public document has a page of its own (REQ-PDC-002)

The site SHALL serve `/document/{id}`, showing the document's title, type, size, date and other
metadata the public read returns, the viewer of REQ-PDC-001, the download, and a link back to its
publication. It SHALL answer only when the document belongs to a publication the anonymous public read
returns; otherwise it SHALL render the site's not-found page with status 404. The publication page
SHALL link each document to its page.

#### Scenario: From a document back to its publication
- **GIVEN** a document of a public publication "Besluit Stationsweg"
- **WHEN** a visitor opens `/document/{id}`
- **THEN** the page SHALL show the document's metadata and a link to "Besluit Stationsweg"

#### Scenario: A document of a draft is not found
- **GIVEN** a document of a draft publication
- **WHEN** a visitor opens its `/document/{id}`
- **THEN** the not-found page SHALL render with status 404 and nothing of the document

### Requirement: The publication downloads as one archive (REQ-PDC-003)

The publication page SHALL offer "Download alles" linking to
`/index.php/apps/opencatalogi/api/{catalogSlug}/{id}/download` when the publication has at least one
document, with the number of documents in its accessible name.

#### Scenario: Download alles
- **GIVEN** a public publication with three documents in catalogue `woo`
- **WHEN** the page renders
- **THEN** it SHALL show "Download alles (3 documenten)" linking to `/index.php/apps/opencatalogi/api/woo/{id}/download`

### Requirement: A document's metadata downloads beside it (REQ-PDC-004)

Beside each document the page SHALL link "Metadata (JSON)" to the document's metadata URL from the
public read, and "Metadata (DiWoo XML)" to its DiWoo record URL when the read carries one. A link whose
URL the read does not carry SHALL be left out, never pointed at a guessed path.

#### Scenario: JSON beside each file
- **GIVEN** a publication read that carries a metadata URL for each document and a DiWoo URL for one
- **WHEN** the page renders
- **THEN** each document SHALL have "Metadata (JSON)", and only that one SHALL have "Metadata (DiWoo XML)"

### Requirement: The page states the reaction period (REQ-PDC-005)

When the public read carries a comment period, the page SHALL show its state as opencatalogi reports
it (REQ-PCP-004): `upcoming` as "Reageren kan vanaf {start}", `open` as "Reageren kan tot en met
{end}" with the reaction form link, and `closed` as "De reactietermijn is verlopen op {end}" without a
form link. Dates SHALL be written in Dutch. A period whose state the read does not carry SHALL show
nothing, never a state the portal computed itself.

#### Scenario: Open, with the form
- **GIVEN** a publication whose comment period is `open` until 2026-11-16 with a zienswijze form
- **WHEN** a visitor opens it
- **THEN** the page SHALL say "Reageren kan tot en met 16 november 2026" and link the form

#### Scenario: Closed, no form
- **GIVEN** a period reported `closed`, ended 2026-11-16
- **WHEN** a visitor opens it
- **THEN** the page SHALL say "De reactietermijn is verlopen op 16 november 2026" and show no form link
