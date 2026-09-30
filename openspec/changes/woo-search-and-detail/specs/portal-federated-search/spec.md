---
status: proposed
---

# Spec: portal-federated-search

## Purpose

A resident finds Woo publications by information category, organisation and
period, and downloads their documents from the publication page. Journey J2 in
hydra `openspec/changes/woo-citizen-journey/journey-map.md`.

## ADDED Requirements

### Requirement: The search block MUST filter on information category and organisation (REQ-WSD-001)

The public search block SHALL facet on every field in `facetFields`, default
`informatiecategorie` and `organization`, in the same request as the results.
It SHALL render one filter group per field that returned buckets, and SHALL
send each selected value as `<field>=<value>`. Each field's selection SHALL
travel in the page address as `f.<field>`. Implements hydra
`woo-citizen-journey` "Both publishing paths MUST create a public, searchable
publication".

#### Scenario: A resident narrows by information category
- **GIVEN** publications with `informatiecategorie` "woo-verzoeken" and "convenanten"
- **WHEN** a resident ticks "woo-verzoeken" in the filters
- **THEN** the request carries `informatiecategorie=woo-verzoeken` and only those publications are listed
- **AND** the page address carries `f.informatiecategorie=woo-verzoeken`
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
- **GIVEN** a search for "fietspad" in category "woo-verzoeken" from 2026-01-01
- **WHEN** another visitor opens the page address
- **THEN** they see the same text, the same ticked category and the same from date
- test: `tests/federated-search.spec.mjs` ("address round trip")

### Requirement: The block MUST describe its search as the saved-search query (REQ-WSD-004)

The block SHALL describe its current search as
`{ text, filters: { informatiecategorie, organisation, periodFrom, periodTo }, catalog }`,
the query shape of contract C2, with the facet field `organization` under the
key `organisation`. Implements hydra `woo-citizen-journey` "A saved search MUST
notify only about publications the resident could have found".

#### Scenario: The query matches what was searched
- **GIVEN** a search for "fietspad" with organisation "org-1" and no period
- **WHEN** the block describes its search
- **THEN** it returns `{ text: "fietspad", filters: { informatiecategorie: [], organisation: ["org-1"], periodFrom: "", periodTo: "" }, catalog: "" }`
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
