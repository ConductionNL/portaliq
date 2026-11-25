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
