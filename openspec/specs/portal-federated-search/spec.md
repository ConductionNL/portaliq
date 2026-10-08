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
