# Searches that found nothing

The Traffic page lists what the public searched for and did not find, so an editor can add the missing page or the missing word.

## Where it comes from

- The site search sends a `search` event with the term and `params.results`, the number of results it found.
- The daily job reads that number as an integer only. A float with a whole value (`0.0`) counts. A string such as `"0"`, a missing value or `null` is unknown.
- `zeroResultSearches` holds each term with `count` for the searches that found exactly nothing. `searchesWithoutCount` counts the searches that never said, so the gap shows instead of turning into a zero.
- A roll-up portal sums both over its members.

## The Traffic page

Under the searched terms, "Gezocht, niets gevonden" lists the terms for the chosen period. Each term links to the public search page of the portal (`headerSearch.route`, else `/zoeken`) with the term filled in. When some searches did not report a count, a sentence says how many.

## The export

The CSV has two more columns: `searchesWithoutCount` and `zeroResultSearches` (one cell, `term (count); term (count)`). The JSON carries both keys as they are stored.

## Not covered

Terms are only kept when the portal keeps search terms. The e2e test and the live check on a dev instance are not run here.
