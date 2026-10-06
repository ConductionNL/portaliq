---
kind: code
depends_on: [portal-federated-search]
---

# Proposal: search-sort-by-relevance

## Summary

Let a visitor sort portal search results by relevance, tolerate a misspelt title with a checked did-you-mean suggestion, and show the publisher how search ranks.

- Rows: matrix row `srch-sort` (the original change), and Woo rows 6.14 "Search tolerates a misspelling and suggests a better query" and 6.17 "Results rank by relevance, and the publisher can explain the ranking" (neither statutory). 6.14 reads yes for titles and the suggestion only, until OpenRegister widens fuzzy matching beyond `_name`.
- Wave: 1.
- Depends on: `portaliq/portal-federated-search` (https://github.com/ConductionNL/portaliq/issues/1224), whose search block this change extends.
- Decision: no Ruben decision governs these rows.

Build rules: openspec/woo-build-rules.md

## Why

A visitor who searches the publications for "parkeervergunning" gets the
newest publications first, whatever they are about. They cannot ask for the
best matches first. The block leaves the option out on purpose, because
nothing behind it ranked, and a sort control that changes the address and
not the list is worse than none.

OpenCatalogi matrix, row `srch-sort`, "Sort search results by date or by
relevance.", rated `partial`, `built.state` `built`, `built.owner`
`ConductionNL/portaliq`. Its note, verbatim: "Sorting by date works. Sorting
by relevance does not exist anywhere reachable." Its `built.evidence`,
verbatim:

> portaliq FederatedSearchBlock.vue:662-670 sortOptions: publicationDate ASC/DESC and title only; the comment above says relevance is absent because nothing ranks; opencatalogi SearchSideBar.vue:63-102 has Relevance/date sort but is never mounted (see srch-facets)

Two competitors are rated `yes`. `ckan`, verbatim:

> source read at ckan-2.12.0: the dataset search sort select offers Relevance, Name ascending/descending and Last Modified (ckan/templates/package/snippets/search_results.html:18-23), passed as sort to package_search. Driven 2026-09-26 on the lab (CKAN 2.12.0, ckanext-dcat 2.4.4, ckanext-scheming 3.1.0): GET /dataset/?sort=metadata_modified desc returned 200; the sort select shows Relevance, Name and Last Modified.

`dkan`, verbatim:

> source read at 4.1.3: search defaults to relevance and sorts by any index field on request (modules/dkan_metastore/modules/dkan_metastore_search/src/QueryBuilderTrait.php:148-162, sort and sort-order parameters on /api/1/search), and /dataset/search exposes 'Last Updated' and 'Alphabetical' sorts (modules/dkan_metastore/modules/dkan_metastore_search/config/install/views.view.dkan_dataset_search.yml:391-418).

Portaliq matrix, row `sib-opencatalogi-srch-sort`, the same capability
mirrored here, rated `partial`, `built.state` `built`. One competitor is
rated `yes`, `liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/search/search-pages-and-widgets/search-results/sorting-search-results 'ordered by relevance score ... by default. With the Sort widget, users can control the order', including 'by the Modified date (newest first by default, or choose oldest first)'.

The lane recorded the pair as `build` on two or more competitors rated
`yes`; no existing change covers relevance.

## What changes

- **"Most relevant" appears when there is a search term.** The public search
  block offers it, and uses it by default for a new search with a term.
  Without a term it is not offered; there is nothing to be relevant to.
- **The block asks OpenRegister's own ranking.** It sends
  `_order[_relevance]=DESC` with `_fuzzy=true`, the parameters OpenRegister's
  search already implements with PostgreSQL trigram similarity.
- **The block checks that ranking happened.** OpenRegister ignores
  `_relevance` without a search term or without the trigram extension. If
  the returned rows carry no relevance score, the block removes the option,
  falls back to the default order, and says "Sorting by relevance is not
  available here." It never shows a control that does nothing.
- **Each result may show how well it matched.** When a score is present, a
  screen reader hears it and a sighted visitor sees nothing extra; the order
  is the information.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| opencatalogi | `srch-sort` | Sort search results by date or by relevance. | partial | A relevance order on the reachable search. |
| portaliq | `sib-opencatalogi-srch-sort` | Sort search results by date or by relevance. | partial | The same, as the portal's search block offers it. |

## Existing work it builds on

- `portal-federated-search` (open): `src/site/components/FederatedSearchBlock.vue`
  and `src/site/lib/federatedSearch.js`, the date and name orders that work.
- openregister `lib/Db/MagicMapper/MagicSearchHandler.php`: the `_relevance`
  column from `similarity()` when `_fuzzy` is set, and ordering on it.

## Out of scope

- Building a ranking. OpenRegister's is used as it is.
- Relevance on the signed-in portal's case lists.
- Changing the default order of a search without a term.

## Sibling halves

- **ConductionNL/opencatalogi** owes the pass-through. Its
  `/api/federation/publications` goes through
  `PublicationService::getAggregatedPublications()`, which re-sorts merged
  local and federated rows in `applyCumulativeOrdering()` by field. It must
  forward `_order[_relevance]` and `_fuzzy` to OpenRegister for local rows,
  keep `@self.relevance` on each row, and say how a federated row without a
  comparable score sorts (after the scored rows, in its source's order).
  Not written here.

## Amendment, 2026-10-05: Woo capability programme, wave 1

Rows 6.14 and 6.17 of the Woo capability register are added to this change. No Ruben decision
governs them. Re-checked on `development` at ca591037: this change is open at 0 of 6 tasks, so the
amendment adds requirements REQ-SSR-004 to REQ-SSR-006 and tasks T07 to T12 and changes nothing
already written.

| row | text | our rating today |
| --- | --- | --- |
| 6.14 | Search tolerates a misspelling and suggests a better query | no |
| 6.17 | Results rank by relevance, and the publisher can explain the ranking | partial (production) |

**Why.** Relevance ordering (REQ-SSR-001) ranks what matched; it does not help a visitor whose term
matched nothing because it was misspelt. OpenRegister's fuzzy match is narrower than it looks:
`MagicSearchHandler` adds `similarity()` for `_name` only (openregister `development`,
`lib/Db/MagicMapper/MagicSearchHandler.php` around line 1388), so a misspelling in a summary or a
document finds nothing. And no publisher can say why one result came before another, because the
ranking is described nowhere they can read.

**What it adds.**

- Every search with a term sends `_fuzzy=true`, not only a relevance-ordered one, so a misspelt title
  is still found.
- A did-you-mean suggestion when a term returns fewer than three results. portaliq builds a word list
  per portal from the titles and summaries of its public publications only, proposes the closest
  correction per word, and offers it only after checking that the corrected search returns results.
- A "How search ranks" explanation in the portal's admin, generated from the same declaration the
  search block sends, so the explanation and the request cannot drift apart.

**What it cannot add here.** Matching misspellings in summaries and document text needs OpenRegister
to widen `similarity()` beyond `_name`. That is an OpenRegister change no plan entry carries. Until
it exists, 6.14 reads `yes` for titles and the suggestion, and the explanation says that summaries
and document text are matched exactly, not fuzzily.
