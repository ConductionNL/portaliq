# portal-federated-search Specification

## Purpose
A visitor searches the publications of every catalogue the portal federates and opens one to read it. This spec covers what that visitor sees: results and a publication page in words, with category and theme names instead of the ids and archive bookkeeping the catalogue stores. The search itself is answered by opencatalogi's federated endpoint; portaliq only reads it and draws the page.

## Requirements

### Requirement: The publication page must show what a visitor needs, in words

The site's publication page MUST show the title, the summary (else the description), the publication date in the site's language, the information category by its name, the themes by their names, and the documents. It MUST NOT show the archive's bookkeeping: Plooi fields, retention fields, the depublication date, the organisation id, the status, the publication kind or any other stored code. A row without a value MUST be left out. A theme whose name cannot be read MUST be left out rather than shown as its id. The search block's information category filter MUST name each category the same way. The 17 category names are the statutory list of Woo art. 3.3, in Dutch and English.

#### Scenario: A Woo decision reads in words
- GIVEN a publication with `wooCategory: infocat014`, a theme "Verkeer en parkeren", Plooi and retention fields and an organisation id
- WHEN a visitor opens its page on the site
- THEN the page shows the summary, "Publicatiedatum", "Informatiecategorie: Woo-verzoeken en -besluiten" and the theme's name
- AND no Plooi or retention label, no `infocat014` and no theme or organisation id appears
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the publication page renders the summary and the visitor rows, no internal field")

#### Scenario: The category filter names its categories
- GIVEN the search answers with category buckets `infocat014` and `infocat016`
- WHEN the filter column renders
- THEN the checkboxes read "Woo-verzoeken en -besluiten" and "Beschikkingen"
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` ("the category filter shows names")

### Requirement: The save actions MUST show only to a signed-in resident who is offered them (REQ-WJE-001)

The search block and the publication page SHALL show "Bewaar deze zoekopdracht"
and "Bewaar in mijn dossier" only when the page shell reports a portal session
AND the resident's manifest offers the matching opencatalogi action. The
signed-in state SHALL come from the shell's existing `/portal/api/session` read
and SHALL NOT be set by page configuration. Implements hydra
`woo-citizen-journey` "The public site MUST learn only whether a resident is
signed in".

#### Scenario: An anonymous visitor searches
- **GIVEN** a visitor without a portal session
- **WHEN** they search and open a publication
- **THEN** neither "Bewaar deze zoekopdracht" nor "Bewaar in mijn dossier" shows
- test: `tests/woo-entry-points.spec.mjs` ("anonymous sees no save action")

#### Scenario: A portal without opencatalogi's actions
- **GIVEN** a signed-in resident whose manifest has no opencatalogi `addToDossier` action
- **WHEN** they open a publication
- **THEN** "Bewaar in mijn dossier" does not show
- test: `tests/woo-entry-points.spec.mjs` ("offered actions")

#### Scenario: Page configuration cannot switch the buttons on
- **GIVEN** a page placement whose authored props set `signedIn: true`
- **WHEN** an anonymous visitor opens the page
- **THEN** the block receives `signedIn: false`
- test: `tests/woo-entry-points.spec.mjs` ("host props win")

### Requirement: A resident MUST be able to keep a publication or one document in a dossier (REQ-WJE-002)

On the publication page, "Bewaar in mijn dossier" SHALL let the resident pick
one of their dossiers or name a new one, and SHALL send
`{ collection | title, publication, attachment }` to
`POST /portal/api/actions/opencatalogi/{addActionId}` with the portal bearer.
Each document row SHALL offer the same with its file id as `attachment`. The
page SHALL confirm "Bewaard in <dossier>." or say "Bewaren is niet gelukt.
Probeer het later opnieuw." Implements hydra `woo-citizen-journey` "A
resident's dossier MUST be owned by the resident and readable by nobody else
unless shared" (the portal half).

#### Scenario: A resident keeps a publication in a new dossier
- **GIVEN** a signed-in resident on a publication page
- **WHEN** they choose "Bewaar in mijn dossier", pick "Nieuw dossier" and name it "Fietspad Oost"
- **THEN** the action receives `{ title: "Fietspad Oost", publication: <id>, attachment: null }`
- test: `tests/woo-entry-points.spec.mjs` ("add to collection body")

#### Scenario: A resident keeps one document in an existing dossier
- **GIVEN** a signed-in resident with the dossier "Fietspad Oost"
- **WHEN** they keep the document "besluit.pdf" in it
- **THEN** the action receives `{ collection: <dossier id>, publication: <id>, attachment: <file id> }`
- test: `tests/woo-entry-points.spec.mjs` ("add to collection body")

### Requirement: A resident MUST be able to save the current search (REQ-WJE-003)

"Bewaar deze zoekopdracht" SHALL ask a name and a frequency ("Direct",
"Dagelijks" by default, "Wekelijks") and SHALL send
`{ title, frequency: immediate | daily | weekly, query }` to
`POST /portal/api/actions/opencatalogi/{saveSearchActionId}`, with `query` the
block's current search object (REQ-WSD-004). Implements hydra
`woo-citizen-journey` "A saved search MUST notify only about publications the
resident could have found".

#### Scenario: A resident saves a daily search
- **GIVEN** a signed-in resident who searched "fietspad" in category "infocat014"
- **WHEN** they choose "Bewaar deze zoekopdracht", name it "Fietspaden" and keep "Dagelijks"
- **THEN** the action receives `{ title: "Fietspaden", frequency: "daily", query: { text: "fietspad", filters: { informatiecategorie: ["infocat014"], organisation: [], periodFrom: "", periodTo: "" }, catalog: "" } }`
- test: `tests/woo-entry-points.spec.mjs` ("save search body")

### Requirement: The search block MUST filter on information category and organisation (REQ-WSD-001)

The public search block SHALL facet on every field in `facetFields`, default
`wooCategory` (the information category) and `organization`, in the same request as the results.
It SHALL render one filter group per field that returned buckets, and SHALL
send each selected value as `<field>=<value>`. Each field's selection SHALL
travel in the page address as `f.<field>`. Implements hydra
`woo-citizen-journey` "Both publishing paths MUST create a public, searchable
publication".

#### Scenario: A resident narrows by information category
- **GIVEN** publications with `wooCategory` "infocat014" and "infocat009"
- **WHEN** a resident ticks "infocat014" in the filters
- **THEN** the request carries `wooCategory=infocat014` and only those publications are listed
- **AND** the page address carries `f.wooCategory=infocat014`
- test: `tests/federated-search.spec.mjs` ("facets per field")

#### Scenario: A link shared before this change still works
- **GIVEN** a page address with `_facets=parkeren` from before this change
- **WHEN** a visitor opens it
- **THEN** "parkeren" is selected in the first facet field
- test: `tests/federated-search.spec.mjs` ("old _facets parameter")

### Requirement: The search block MUST filter on a publication period (REQ-WSD-002)

The block SHALL offer a from date and a to date. It SHALL send them as
`<periodField>[gte]=<from>` and `<periodField>[lte]=<to>T23:59:59Z`, with
`periodField` defaulting to `publicationDate`. A date that does not parse
SHALL NOT be sent.

#### Scenario: A resident looks at one year
- **GIVEN** a resident on the search page
- **WHEN** they set "Van" to 2026-01-01 and "Tot" to 2026-12-31
- **THEN** the request carries `publicationDate[gte]=2026-01-01` and `publicationDate[lte]=2026-12-31T23:59:59Z`
- test: `tests/federated-search.spec.mjs` ("period range")

#### Scenario: A typed date that is not a date
- **GIVEN** a page address with `periodFrom=gisteren`
- **WHEN** the block builds its request
- **THEN** no `publicationDate[gte]` is sent
- test: `tests/federated-search.spec.mjs` ("period range")

### Requirement: Every filter MUST survive a shared link (REQ-WSD-003)

The block SHALL restore the text, every facet selection and the period from
the page address, so a link opens the same search.

#### Scenario: A resident shares a filtered search
- **GIVEN** a search for "fietspad" in category "infocat014" from 2026-01-01
- **WHEN** another visitor opens the page address
- **THEN** they see the same text, the same ticked category and the same from date
- test: `tests/federated-search.spec.mjs` ("address round trip")

### Requirement: The block MUST describe its search as the saved-search query (REQ-WSD-004)

The block SHALL describe its current search as
`{ text, filters: { informatiecategorie, organisation, periodFrom, periodTo }, catalog }`,
the query shape of contract C2, with the facet field `wooCategory` under the key
`informatiecategorie` and `organization` under `organisation`. Implements hydra `woo-citizen-journey` "A saved search MUST
notify only about publications the resident could have found".

#### Scenario: The query matches what was searched
- **GIVEN** a search for "fietspad" with category "infocat014", organisation "org-1" and no period
- **WHEN** the block describes its search
- **THEN** it returns `{ text: "fietspad", filters: { informatiecategorie: ["infocat014"], organisation: ["org-1"], periodFrom: "", periodTo: "" }, catalog: "" }`
- test: `tests/federated-search.spec.mjs` ("search query object")

### Requirement: The publication page MUST list and offer every document for download (REQ-WSD-005)

The publication page SHALL read the publication's attachments from
`<endpoint>/<id>/attachments` and list each with its name, type and size and a
download link. A link SHALL be http(s) or same-origin, otherwise the row shows
without a link. Without documents it SHALL say "Deze publicatie heeft geen
documenten." A failed attachment call SHALL leave the rest of the page intact.
Implements hydra `woo-citizen-journey` "Both publishing paths MUST create a
public, searchable publication".

#### Scenario: A resident downloads a decision
- **GIVEN** a public publication with the files "besluit.pdf" (120 KB) and "inventaris.xlsx"
- **WHEN** a resident opens its page
- **THEN** the section "Documenten" lists both with a download link, and "besluit.pdf" shows "PDF" and "120 kB"
- test: `tests/publication-documents.spec.mjs` ("documents from the envelope")

#### Scenario: A file with an unsafe link
- **GIVEN** an attachment whose `downloadUrl` is `javascript:alert(1)`
- **WHEN** the page renders
- **THEN** the row shows the name without a link
- test: `tests/publication-documents.spec.mjs` ("unsafe link")

### Requirement: A link with q opens the search for that term (REQ-SRQ-001)

The federated search block MUST read its term from `_search`, or from `q` when only `q` is on the address. When the visitor searches again, the block MUST write the term into the parameter it was read from, so a visitor who arrived with `q` keeps `q` in the address; otherwise it MUST write `_search`. An empty term MUST remove the parameter.

#### Scenario: A resident follows a link with q
- GIVEN a link to `/zoeken?q=Lindelaan`
- WHEN the resident opens it
- THEN the search field holds "Lindelaan" and the results are for Lindelaan
- @e2e exclude pinned by `tests/federated-search.spec.mjs` ("?q= opens the search for that term"); the block adopts `readSearchState()` on mount

#### Scenario: She searches again
- GIVEN the resident arrived with `?q=Lindelaan`
- WHEN she searches for "fietspad"
- THEN the address reads `q=fietspad` and reopening it shows the same search
- @e2e exclude pinned by `tests/federated-search.spec.mjs` ("a visitor who came with q keeps q")

### Requirement: The organisation filter reads the organisation's name

The public site record MUST carry the id and the name of the portal's own organisation as `organisation: {id, name}`, or null when the portal names none that resolves. The search block's organisation filter MUST show that name for a bucket whose value is that id, and MUST still send the id as the filter value. A bucket of another organisation MUST keep the label the search API gave it.

#### Scenario: Zuiddrecht's publications
- GIVEN the Zuiddrecht portal belongs to the organisation "Gemeente Zuiddrecht" with id `bacda7a4-…`
- AND 13 publications name that id as their `organization`
- WHEN a visitor opens the Woo search
- THEN the "Organisatie" filter reads "Gemeente Zuiddrecht (13)"
- AND ticking it sends `organization=bacda7a4-…`
- @e2e exclude pinned by `tests/publication-documents.spec.mjs` and `ContentControllerTest::testSiteNamesItsOrganisation`; the live check on the rehearsal follows the merge

### Requirement: The result page travels in the address (REQ-CRG-009)

The search block SHALL page its results by number, SHALL request the chosen page with `_page` and `_limit`, and SHALL keep the page number in the page address, so a link opens the same page of the same results.

#### Scenario: A visitor shares page 3
- **GIVEN** a search for "fietspad" with five pages of results
- **WHEN** a visitor goes to page 3 and another visitor opens that page address
- **THEN** the request carries `_page=3` and the second visitor sees page 3 of the same results
- @e2e exclude the request shape is a pure URL build. Covered by `tests/federated-search.spec.mjs` ("sends the page", `_page`) and `tests/federated-search-reads-and-pages.spec.mjs` ("the search state reads every filter from the address").

### Requirement: The result count is announced (REQ-CRG-010)

When results load, the search block SHALL put the number of results, or that nothing was found, in a polite live region, so a screen reader announces it without moving focus.

#### Scenario: A search finds nothing
- **WHEN** a visitor searches for a term with no results
- **THEN** the live region reads "Zoekresultaten geladen. Geen resultaten gevonden." and focus stays in the search field
- @e2e exclude the sentence is state the block computes from the loaded total, with no flow of its own beyond a search. Covered by `tests/federated-search.spec.mjs` (the live-region status, "and says so once in the live region").

#### Scenario: A search finds twelve results
- **WHEN** a visitor searches for a term with twelve results
- **THEN** the live region names twelve results
- @e2e exclude same as "A search finds nothing". Covered by `tests/federated-search.spec.mjs` (the counted live-region sentence).

### Requirement: The search box suggests publications while you type (REQ-SST-001)

After at least three typed characters and 250 ms without typing, the search block and the header search box SHALL show at most five publication titles from the same federation search, each with its kind. The list SHALL show nothing when the request fails or takes longer than one second, and searching SHALL keep working. A suggestion SHALL open that publication.

#### Scenario: Three suggestions
- **WHEN** a visitor types "fietspad Lin" and pauses
- **THEN** the list shows matching titles such as "fietspad Lindelaan verlichting" with the kind "Woo-besluit"

#### Scenario: Two characters
- **WHEN** a visitor types "fi"
- **THEN** no suggestion list opens

#### Scenario: The search source is down
- **WHEN** the suggestion request fails
- **THEN** no list and no error show, and pressing Zoeken still runs the search

### Requirement: The suggestion list works by keyboard and screen reader (REQ-SST-002)

The search input SHALL be a combobox controlling a listbox. Arrow keys SHALL move the active suggestion, Enter SHALL open it, Escape SHALL close the list and keep the text. The live region SHALL announce the number of suggestions when the list opens.

#### Scenario: Keyboard only
- **WHEN** a keyboard user types "fietspad", presses arrow down twice and Enter
- **THEN** the second suggestion's publication opens

#### Scenario: Screen reader
- **WHEN** the list opens with three suggestions
- **THEN** the live region announces "3 suggesties"

### Requirement: Every result from another catalogue MUST name that catalogue (REQ-SNF-001)

A result whose `@self.directory` names another catalogue SHALL display that directory with the
source label. A result of this portal, carrying no `@self.directory` or the API's own word `local`,
SHALL display no source, and the word `local` SHALL never be shown.

#### Scenario: A federated row names its peer
@e2e exclude Asserted on the rendered block in `tests/site-woo-pages.spec.mjs`; the dev rig has no second catalogue to federate with.
- **GIVEN** results drawn from this portal and from `opencatalogi.nl`
- **THEN** the row from `opencatalogi.nl` SHALL show "opencatalogi.nl" as its source
- **AND** the row of this portal SHALL show no source

#### Scenario: A row the API calls local
@e2e exclude Asserted in `tests/federated-search.spec.mjs`; a pure mapping of the response row.
- **GIVEN** a row with `@self.directory` set to `local`, or no `@self` at all
- **WHEN** it is mapped for the block
- **THEN** its `directory` SHALL be empty and the card SHALL show no source

### Requirement: An anonymous visitor MUST be able to search federated publications

The site renderer SHALL offer a `federatedSearch` block that queries
OpenCatalogi's `/api/federation/publications` with free text, pagination and
facet buckets in a single request. It SHALL apply no visibility rule of its own
and SHALL NOT query OpenRegister directly.

The endpoint SHALL be configurable per placement, defaulting to the same
instance, so a portal can read another catalogue without a code change.

#### Scenario: A term matches a publication

- **GIVEN** a portal page placing the `federatedSearch` block
- **WHEN** an anonymous visitor searches for a term present in one publication
- **THEN** that publication is listed, with its title and a link
- @e2e `tests/e2e/site-federated-search.spec.ts` (S30)

#### Scenario: An empty term is not sent as an empty parameter

- **GIVEN** no term has been entered
- **THEN** the request omits `_search` entirely rather than sending `_search=`
- **BECAUSE** those are two different requests and only one of them means
  "everything"
- @e2e exclude Asserted in `tests/federated-search.spec.mjs`; a query-string
  shape is not observable from the rendered page.

#### Scenario: The block mounts at a public origin

- **GIVEN** the site bundle running with no Nextcloud session
- **THEN** the block issues its request with `fetch` and no CSRF token
- **AND** it imports nothing from `@nextcloud/*`
- @e2e `tests/e2e/site-federated-search.spec.ts` (S30b)

### Requirement: A malformed federated row MUST NOT blank the list

A row that omits fields the renderer reads SHALL degrade to a titled entry.
One peer running an older schema SHALL NOT prevent the rows around it from
rendering.

#### Scenario: A row with almost no fields still renders

- **GIVEN** a result object with no name, no id and no `@self`
- **THEN** it renders as "Zonder titel" with directory `local`
- **AND** every other result in the same response still renders
- @e2e exclude Asserted in `tests/federated-search.spec.mjs`; producing a
  malformed row from a real federated peer is not reachable from an e2e run.

### Requirement: Facet buckets MUST be read in both dialects the endpoint speaks

The block SHALL normalise both `{data: {buckets: [{value, count}]}}` (object
and metadata fields) and `{buckets: [{key, results}]}` (OpenCatalogi's virtual
facets) into one bucket shape.

**Reading only one dialect renders an empty facet column, which on screen is
identical to "this field has no values"** — the defect presents itself as data
and is never reported as a bug.

#### Scenario: An object-field facet produces filters

- **GIVEN** `_facets[categories][type]=terms` in the response
- **THEN** the facet column lists each category with its count
- **AND** selecting one narrows the results
- @e2e `tests/e2e/site-federated-search.spec.ts` (S32)

### Requirement: A superseded request MUST NOT overwrite newer results

Each request SHALL carry a monotonic identifier, and a response whose
identifier is no longer current SHALL be discarded.

**A slow first request resolving after a fast second one replaces new results
with old ones.** It presents as "the page ignored my search" and is invisible
on a fast connection, which is where it is developed and tested.

#### Scenario: A slow response is discarded

- **GIVEN** a search whose response is outstanding
- **WHEN** a second search completes first
- **THEN** the first response, when it arrives, changes nothing
- @e2e exclude Requires deterministic response ordering; asserted by the
  sequence guard in the component rather than through the browser.

### Requirement: A visitor's search reaches the text inside public documents (REQ-PFS-CONTENT-001)

Whenever the state has a non-empty term, `buildRequestUrl()` SHALL send `_content=true`, the opt-in
opencatalogi's `add-document-content-search` defines (SCH-PFTS-CONTENT-001), unless the portal's
setting `searchInsideDocuments` is false. A result row whose `resultType` (or `@self.schema`) marks it
as a document SHALL link to that document's own public page (`/document/{id}`, decision D11), and
SHALL name the publication it belongs to. A word that appears in a document only under a redaction
SHALL NOT make that document a result, because only the published, redacted file is searched.

#### Scenario: A word in the body of a document finds it
- **GIVEN** a public publication whose PDF contains "geluidsscherm A2" in its text and nowhere in its metadata
- **WHEN** a visitor searches "geluidsscherm"
- **THEN** the request SHALL carry `_content=true`
- **AND** a result SHALL link to that document's own page and name its publication

#### Scenario: A redacted word finds nothing
- **GIVEN** a partly public document whose published copy has the name "Jansen" redacted
- **WHEN** a visitor searches "Jansen"
- **THEN** that document SHALL NOT be among the results

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

A `featuredSubjects` widget SHALL list the featured rows of `GET /api/themes?featured=true` (a row
whose `featured` is true, or, without a `featured` key, a row of opencatalogi's REQ-SUB-002
projection, which carries `featuredOrder`; a row with neither is not featured), in `featuredOrder`
then `title`, each with its image, title, summary and
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
answer SHALL be left out, never shown as 0. The search page SHALL apply the filter a count's link
names (`f.<field>`) even when its placement does not offer that field as a facet.

#### Scenario: Counts link into the search
- **GIVEN** 40 public publications, 12 in category "Raadsstukken"
- **WHEN** the home page with a `portalCounts` widget per category is opened
- **THEN** it SHALL show "Raadsstukken 12" linking to the search with that category set

#### Scenario: An officer sees the public count
- **GIVEN** the same portal and 5 draft publications in "Raadsstukken"
- **WHEN** a signed-in officer opens the home page
- **THEN** the widget SHALL still show 12

#### Scenario: A subject's count opens the search for that subject
- **GIVEN** a subject with 3 public publications, and a search page whose placement facets on category and organisation only
- **WHEN** a visitor follows the subject's count on the home page
- **THEN** the search SHALL list those 3 publications, not every publication

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
