---
kind: code
depends_on: [portal-federated-search, publication-detail-page-complete, home-and-theme-landing-pages]
---

# Proposal: search-filter-by-kind

Woo capability programme, round 1, wave 2. Row 6.30.

| row | text | our rating today |
| --- | --- | --- |
| 6.30 | Results filter by kind of record: publication, document or subject | no |

Implements Ruben's decision **D11**: documents are search hits of their own, resolving to the
document's own public page, so the kind filter has three real kinds.

## Summary

Let portal search results filter by kind of record: publication, document or subject.

- Rows: 6.30 "Results filter by kind of record: publication, document or subject" (not statutory).
- Wave: 2.
- Depends on: `opencatalogi/subjects-as-first-class-records` (https://github.com/ConductionNL/opencatalogi/issues/1764), `portaliq/publication-detail-page-complete` (https://github.com/ConductionNL/portaliq/issues/1220), `portaliq/home-and-theme-landing-pages` (https://github.com/ConductionNL/portaliq/issues/1219), and outside the plan `opencatalogi/add-document-content-search` (no issue; https://github.com/ConductionNL/opencatalogi/tree/development/openspec/changes/add-document-content-search).
- Decision: D11 (2026-10-05), documents are search hits of their own, so the filter has three real kinds.

Build rules: openspec/woo-build-rules.md

## Why

What portaliq does today, read on `development` at ca591037: the search block lists publications
only, with facets on category, date, organisation and theme. A visitor who wants "the subject page on
parking" or "the document that mentions the A2" cannot ask for that kind of result.

opencatalogi's planned `subjects-as-first-class-records` (wave 1, REQ-SUB-004) makes the public search
return subjects and documents as results of their own, each with `resultType` (`publication`,
`document`, `subject`), and counts `resultType` as a facet. This change is the portal side.

## What changes

1. A "Soort" filter in the search block with the three kinds and their counts from the `resultType`
   facet. Choosing one sends `resultType`; choosing none sends nothing, so the default stays what the
   endpoint returns today.
2. Each result renders by its kind: a publication links to its page, a document to `/document/{id}`
   (from `publication-detail-page-complete`) with its publication named, a subject to
   `/onderwerp/{slug}` (from `home-and-theme-landing-pages`) with its publication count.
3. The chosen kind is part of the address, so a filtered search can be shared.

## What does not change

- The search endpoint and its scope, which are opencatalogi's.
- The other facets.

## Dependencies

- Planned, opencatalogi, wave 1: `subjects-as-first-class-records`, REQ-SUB-004 (`resultType` on every
  result and as a facet; a subject result carries `id`, `slug`, `title`, `summary`, `image`,
  `publicationCount`, `url`).
- Planned, portaliq, wave 2: `publication-detail-page-complete` (the document page),
  `home-and-theme-landing-pages` (the subject page).
- Open, opencatalogi, outside the plan: `add-document-content-search`, amended for D11, for document
  hits from inside the text.

**App absent.** An opencatalogi without `resultType` answers no such facet; the block then offers no
"Soort" filter and renders every row as a publication, as today, rather than a filter that does
nothing.

## Wave and done

Wave 2. Done means merged on `development` with CI green. 6.30 then reads `yes` (build), and
`production` only once a portaliq store release carries it.
