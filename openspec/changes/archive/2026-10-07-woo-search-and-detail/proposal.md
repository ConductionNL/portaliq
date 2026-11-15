---
kind: code
depends_on: [portal-federated-search]
---

# Proposal: woo-search-and-detail

## Why

A resident looking for a Wet open overheid (Woo) decision can search by text
today, and narrow the list by theme. They cannot narrow it by information
category, by the organisation that published it, or by period. On the
publication page they read the metadata, but they cannot see or download the
documents. Journey J2 of the Woo citizen journey stops there (hydra
`openspec/changes/woo-citizen-journey/journey-map.md`, steps 2.2 and 2.3).

## What changes

- **Three filters on the search block.** Information category and organisation
  as facet lists, and a period as a from and to date. Each filter travels in
  the page address, so a shared link opens the same search.
- **Documents on the publication page.** The page lists every document of the
  publication with its name, type and size, and a download link per document.
- **The search state as one object.** The block describes the current search
  as `{ text, filters: { informatiecategorie[], organisation[], periodFrom,
  periodTo }, catalog }`, the shape opencatalogi stores for a saved search
  (contract C2). `woo-journey-entry-points` sends this object when a resident
  saves the search. The facet field `wooCategory` maps to the query key
  `informatiecategorie`, `organization` to `organisation` (hydra C6).
- **The first-load budget holds.** Both blocks already load on demand. The
  visitor's entry stays within 412 KiB (`webpack.site.js`).

## Hydra requirements it implements

- `woo-citizen-journey`: "Both publishing paths MUST create a public, searchable
  publication" (the portal half: the publication is found with its documents).
- `woo-citizen-journey`: "A saved search MUST notify only about publications the
  resident could have found" (the portal half: the saved query is exactly the
  query the block ran).

## Out of scope

- The save buttons and the signed-in state: `woo-journey-entry-points`.
- The publication properties themselves (`wooCategory` exists; `period`,
  `publicationKind` and `caseReference` are new): opencatalogi, contract C6.
- Faceting on federated peers that do not declare these properties. Their rows
  still appear, without facet counts.
