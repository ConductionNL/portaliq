# How the public search ranks

A search with a term shows the best matches first. A search without a term keeps the newest first, as before.

## What "Meest relevant" ranks on

- The order is OpenRegister's own ranking: trigram similarity between the term and the title. Nothing in portaliq ranks.
- Every search with a term also asks for fuzzy matching, so a title with a typo is still found.
- Summaries and the text inside documents are matched on exact words only. Fuzzy matching there waits on an OpenRegister change.
- Results from other catalogues arrive without a score. They come after the scored results.

The portal's own page in the admin shows the same list under "How search ranks". It is rendered from the declaration the search request is built from (`RANKING` in `src/site/lib/federatedSearch.js`), so the page and the search cannot disagree.

## When ranking is not available

OpenRegister ranks only when PostgreSQL has the `pg_trgm` extension. Without it the answers carry no score. The search block then drops "Meest relevant" for the rest of the visit, searches again in the default order, and says "Sorteren op relevantie is hier niet beschikbaar." once.

## "Bedoelde u"

When a search with a term finds fewer than three results, the block asks `GET /index.php/apps/portaliq/api/site/search/suggest?portal={slug}&q={term}`.

- The words come from the titles and summaries of what the anonymous public search lists. A draft never lends a word.
- A daily background job (`SuggestionWordListJob`) rebuilds the word list of every published portal.
- Each unknown word is replaced by the nearest listed word: one edit for words up to five letters, two for longer words, the more frequent word on a tie.
- The correction is offered only when it finds at least one result. The route is public and rate limited.

## Not covered here

The e2e test (`tests/e2e/search-sort-by-relevance.spec.ts`) and the live check on a dev instance with `pg_trgm` are not run in this build.
