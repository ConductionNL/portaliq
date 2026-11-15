## Why

The guardian overview's "Deze maand op school" (a calendar block with `display: tiles` and
`range: month`) started at today. Proof run 2 opened it on Thursday 8 October and the 7 October
items were gone, while the board (De Wilgenboom, MijnOverzicht) shows the whole current week.

## What Changes

- `CalendarTiles.vue` takes the block's `range`. With `month` the tiles start at this week's
  Monday instead of at today; the range has already kept the items inside this month. Other ranges
  and no range start at today, as before.
- A tile on today carries `aria-current="date"` and the set's accent line at its start.
- `ContributionPage.vue` hands the range to the tiles.
- `tests/site-look/month-keeps-this-week.spec.mjs`.

## Declaration (learniq, unchanged)

`{type: "calendar", label: "This month", display: "tiles", range: "month", sources: [...]}`.
Nothing new to declare.

## Impact

- Only tiles with `range: month`.
