## Why

The Zuiddrecht Home board places "Mijn Zuiddrecht" and "Openbare informatie" in an aside that is 340px wide, beside the news. The page grid has 12 columns, and no number of them comes to 340px. Live, the aside drew 405px wide and the news 65px narrower.

## What Changes

- A grid page body MAY declare `aside: {width, gap}` in pixels.
- The site then draws a run as a main column plus that fixed aside. This only applies to a run with a widget at the left edge, a widget ending at the right edge, and nothing else except full-width widgets. Every other run keeps the 12-column grid.
- The content API serves the option. The Zuiddrecht Home declares `{width: 340, gap: 56}`.
