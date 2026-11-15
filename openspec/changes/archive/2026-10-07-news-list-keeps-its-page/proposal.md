# Proposal: news-list-keeps-its-page

## Why

Ruben reviewed the primary-school recordings (2026-10-02). On the staff News page, every save or publish sent staff back to page 1 of the list. The handlers reloaded the whole browser page, because a manifest handler gets no handle on the list it was started from. Filters, search and sort survived the reload (CnIndexPage keeps them in the address), the page number did not.

## What changes

- `src/lib/listRefresh.js` remembers the params every list last fetched with through the shared object store (`fetchCollection(type, params)`), and `refreshList(type)` asks for the same list again: same page, sort, filters and search. The rows and the pagination read from the store, so they update in place.
- The News handlers (`New news item`, `Change`, `Publish`, `Take back`) refresh the `portaliq-newsItem` list that way. Only a list that was never fetched in this tab falls back to reloading the page.

## Not changed

- The account, invitation and access-request handlers still reload the page; they can adopt `refreshList` the same way.
