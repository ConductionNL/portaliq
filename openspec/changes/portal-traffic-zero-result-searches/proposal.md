---
kind: code
depends_on: []
---

# Proposal: portal-traffic-zero-result-searches

Woo capability programme, round 1, wave 1. Row 16.3.

| row | text | our rating today |
| --- | --- | --- |
| 16.3 | What the public searched for and did not find | partial (production) |

No Ruben decision governs this row.

## Summary

Count the public searches that found nothing and show them on the Traffic page, so an organisation sees what the public looked for and did not find.

- Rows: 16.3 "What the public searched for and did not find" (not statutory).
- Wave: 1.
- Depends on: nothing.
- Decision: no Ruben decision governs this row.

Build rules: openspec/woo-build-rules.md

## Why

What portaliq does today, read on `development` at ca591037:

- The public search block reports each search to the traffic client:
  `client.track('search', {searchTerm, results: total})` in `FederatedSearchBlock.vue`
  (`reportSearch()`, around line 1020). The result count is sent.
- `TrafficRollup` builds the daily `searches` dimension with
  `perEvent(name: 'search', keys: ['searchTerm', 'params.search_term'])`. It counts terms and drops the
  result count, so nobody can ask which searches found nothing.
- The Traffic page shows the top search terms only.

## What changes

1. The daily roll-up gains a `zeroResultSearches` dimension: per term, the number of searches whose
   result count was 0. A search whose event carries no result count is not counted as zero; it is
   counted under `searchesWithoutCount` so the gap is visible.
2. A roll-up portal sums its members' `zeroResultSearches` as it sums the other dimensions.
3. The Traffic page shows "Gezocht, niets gevonden" per portal and period, with each term, its count,
   and a link that opens the public search with that term.
4. The export of the daily records includes the new dimension.

## What does not change

- The traffic client, the consent rules and the event schema. The result count is already sent.
- The existing `searches` dimension.

## Dependencies

None. When the search block runs on a portal where the traffic client is off (no consent, or
traffic disabled), nothing is counted, as today.

## Wave and done

Wave 1. Done means merged on `development` with CI green. 16.3 then reads `yes` (build), and
`production` only once a portaliq store release carries it.
