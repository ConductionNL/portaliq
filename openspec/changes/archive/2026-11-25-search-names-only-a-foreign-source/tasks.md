# Tasks: search-names-only-a-foreign-source

Kind: code. Board canon: `Zoeken` (design-system `screens-src/zuiddrecht/`).

## 1. The source chip

- [x] 1.1 `src/site/lib/federatedSearch.js`: `foreignDirectory()`; `toResult()` sets `directory` to ''
  for a row of this portal (REQ-SNF-001).
  - `tests/federated-search.spec.mjs`
- [x] 1.2 `src/site/components/FederatedSearchBlock.vue`: `metaItems()` adds the source only when
  `directory` is set (REQ-SNF-001).
  - `tests/site-woo-pages.spec.mjs` `a result card names its source only for a row from another catalogue`

## 2. Live

- [x] 2.1 After merge, on the Zuiddrecht portal: a search shows no "Bron: local" on any own card.
      Done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (MariaDB, php -S) with openregister, opencatalogi
      and portaliq from development, `portaliq:example-site:install zuiddrecht`, two published
      publications: `/zoeken` searched "fietspad" in Chromium, 2 own results, no "Bron" line on either card.
