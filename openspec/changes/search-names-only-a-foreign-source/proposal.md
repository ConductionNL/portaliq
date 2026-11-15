# Proposal: search-names-only-a-foreign-source

Kind: code. Found by the Zuiddrecht demo audit of 13 October 2026 (Woo journey).

## Why

Every card in the search block showed "Bron: local". `toResult()` set `directory` to `local` for a
row without `@self.directory`, as `portal-federated-search` asked ("a result carrying none SHALL be
labelled `local` rather than left blank"), and `metaItems()` always added the source chip. On the
municipality's own portal every own publication therefore carried the API's internal word. The
`Zoeken` board (design-system `screens-src/zuiddrecht/Zoeken.dc.html`) draws a result card with its
title, summary, date, category and documents, and no source.

## What changes

- A row of this portal (no `@self.directory`, or the API's own word `local`) gets no source chip.
- A row from another catalogue keeps its source chip with that catalogue's directory, so federation
  stays visible.

Why no chip rather than the organisation's name: the board draws none, and the portal's own name
already heads every page of the site, so repeating it on each card adds nothing a visitor needs. It
also needs no new data path into the block.

## What does not change

- The request, the filters, the sort and the rest of the metadata row.
- The rule that a malformed row degrades to a titled entry.
