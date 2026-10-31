---
kind: code
---

# Proposal: record-and-search-links-keep-their-portal

## Why

The Zuiddrecht live test on 14 October 2026 opened a council meeting from the public records list and a result from the search. Both links landed on another portal: the record link was a bare `?record=<id>`, which replaced the whole query (`portal`, `route`, `lang`), and a search result cleared the query and wrote only `route`. On a host that serves several portals, an address without `portal` opens another portal or none.

## What changes

- `siteAddress.js` gains `addressOfRecord(id, href, config)`: the page's own route through `addressOfRoute`, plus `record`.
- `PublicRecordsBlock::hrefFor` links a record through `addressOfRecord`.
- `FederatedSearchBlock::detailHref` and `IntakeCatalogueBlock::hrefFor` build their links through `addressOfRoute`, so the portal and the language stay and a host that serves the site at its root gets a short address.
