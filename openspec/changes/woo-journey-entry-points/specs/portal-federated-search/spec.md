---
status: proposed
---

# Spec: portal-federated-search

## Purpose

A signed-in resident keeps a publication or a document in a dossier, and saves
a search, from the public site. Journeys J2.4, J3.1 and J6.1 in hydra
`openspec/changes/woo-citizen-journey/journey-map.md`.

## ADDED Requirements

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
- **GIVEN** a signed-in resident whose manifest has no opencatalogi `addToCollection` action
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
- **GIVEN** a signed-in resident who searched "fietspad" in category "woo-verzoeken"
- **WHEN** they choose "Bewaar deze zoekopdracht", name it "Fietspaden" and keep "Dagelijks"
- **THEN** the action receives `{ title: "Fietspaden", frequency: "daily", query: { text: "fietspad", filters: { informatiecategorie: ["woo-verzoeken"], organisation: [], periodFrom: "", periodTo: "" }, catalog: "" } }`
- test: `tests/woo-entry-points.spec.mjs` ("save search body")
