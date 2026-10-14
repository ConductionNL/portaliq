---
kind: code
---

# Proposal: search-reads-q-from-the-address

## Summary

`/zoeken?q=Lindelaan` opened the search page with an empty field and no results: the search block reads the term only from `_search`. A link with `q`, the name most sites and search engines use for a search term, now opens the search for that term, and a visitor who arrived that way keeps `q` in the address when searching again.

## Why

Found in the live test of the Zuiddrecht demo portal on 13 and 14 October 2026. Typing in the field worked (it writes `_search`), but a link someone wrote by hand, a bookmark or a campaign link with `?q=` showed nothing.

## What changes

- `readSearchState()` takes the term from `_search`, or from `q` when only `q` is on the address.
- `writeSearchState()` writes the term back into the parameter it came in: `q` when the visitor arrived with only `q`, `_search` otherwise. Clearing the term removes it.
- `_search` stays the parameter the portal itself writes (home page box, suggestions, traffic links).

## Screens

No board changes: the Zoeken page looks the same; only the address it reads changes.
