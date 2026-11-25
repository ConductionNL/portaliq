## Why

The academy participant's overview shows the next course day as a highlight, then "Daarna" lists
the course days after it. Both read the same collection, so "Daarna" repeated the highlight's day
(proof run 2: "Uw volgende cursusdag" twice). A list needs to start after the rows a highlight
above already shows.

## What Changes

- `CollectionListKeys` keeps a collection block's `skip`: a whole number from 1 to 50.
- `windowRows()` and the opened whole list leave out the first `skip` rows after the order and
  before the limit (`skipRows()` in `src/shared/listWindow.js`).
- Tests: `tests/Unit/Contribution/CollectionSkipTest.php`, `tests/site-look/collection-skip.spec.mjs`.

## Declaration for learniq (FIX-L)

On the participant overview's "Daarna" block, the same collection and order as the highlight, with
`"skip": 1`, for example
`{type: "collection", collection: "participantSessions", sort: {field: "date", direction: "asc"}, skip: 1, limit: 4, display: "rows", ...}`.

## Impact

- A block without `skip` is unchanged.
