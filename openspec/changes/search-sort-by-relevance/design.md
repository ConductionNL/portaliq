# Design: search-sort-by-relevance

Read at portaliq `development` `eeda3fa`, openregister `development` and
opencatalogi `development`.

## What exists

- `src/site/components/FederatedSearchBlock.vue:130-137` explains in a
  template comment why no relevance option is offered;
  `sortOptions()` (lines 662-670) returns the default order, date both ways
  and name both ways, labelled in Dutch.
- `src/site/lib/federatedSearch.js:37` `buildRequestUrl()` turns `field:DIR`
  into `_order[field]=DIR` (line 68).
- The block queries `/index.php/apps/opencatalogi/api/federation/publications`
  (default prop, line 514).
- openregister `MagicSearchHandler.php:340-370` adds a `_relevance` column
  (`similarity(t._name, term) * 100`) when `_fuzzy` is true and `pg_trgm` is
  installed; lines 3219-3231 order by it, and silently skip it without a
  search term or without `pg_trgm`; line 3487 sets `@self.relevance`.
- opencatalogi `PublicationService::getAggregatedPublications()` (line 1344)
  merges local and federated rows and re-sorts them in
  `applyCumulativeOrdering()` (line 2459).

## D1. Offer relevance only with a term, and by default then

`sortOptions()` gains `{ value: '_relevance:DESC', label: 'Meest relevant' }`
when `query` is not empty. A new search with a term starts on it; a search
without a term starts on the default order, as today. The Dutch label follows
the block's existing Dutch labels. `buildRequestUrl()` adds `_fuzzy=true`
whenever the order is `_relevance`.

## D2. Prove the order was applied

After a response for a `_relevance` order, the block checks whether the
first result carries `@self.relevance`. If none does, the backend ignored
the order: the block sets `relevanceUnavailable`, removes the option for the
rest of the visit, re-runs the search in the default order, and shows
"Sorting by relevance is not available here." once, in the result count's
live region. This is the honesty the block's own comment asks for.

## D3. Mixed federated results

Until opencatalogi's sibling half lands, federated rows arrive without a
comparable score. The block does not re-sort anything itself; it shows what
the endpoint returns. D2 applies to the first row only, so a local-first
ranked list with federated rows after it is accepted.

## D4. The score is for assistive technology only

When a row carries `@self.relevance`, the result link gets an
`aria-describedby` to a visually hidden "Match: {n} percent". A sighted
visitor sees the order, not a number that invites comparing unlike
catalogues.

## Risks

- **Trigram similarity on the name is a narrow ranking.** It ranks on
  `_name` only. That is OpenRegister's current ranking, stated in the docs,
  and improving it is OpenRegister's work.
- **Every relevance search costs a second request when unavailable.** Only
  once per visit, because the option is removed after the first miss.

## What this deliberately does not do

- No ranking in portaliq.
- No change to the order of a search without a term.
