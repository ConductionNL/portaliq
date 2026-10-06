## Why

The fresh install of the four school portals (proof run 1, 06 Oct, item 9) showed content blocks
that did not look like the boards. On `development` (after #1206 made tables and link lists
readable) a content page still shows:

- a table without its frame, with an untinted header row and its caption as small body text;
- every melding (`nlAlert`) as text flush against a coloured line, with no room inside and no tint,
  where the boards draw a card ("Online melden", "Liever bellen?");
- three link lists on the home page as three `nav` landmarks without a name (axe
  `landmark-unique`), and link targets 19px high (axe `target-size`, serious, on the proof build).

The Utrecht components read all of this from `--utrecht-table-*`, `--utrecht-alert-*` and
`--utrecht-link-list-*` tokens that no shipped set declares.

## What Changes

- `css/site-theme.css`: a table gets a frame and rounded corners, a tinted header row and its
  caption as a heading; a melding gets padding, the tint of its kind and a gap between heading and
  text, and fills its grid cell so two meldingen side by side are one height; a link in a list is
  at least 24px high. Every value reads the Utrecht token first, then the set's own `--nldesign-*`
  token (`--nldesign-color-border`, `-background-hover`, `-primary-light`,
  `-component-status-badge-*-background-color`, `-website-border-radius-large`), and ends in a
  keyword. No literal colours.
- `NlLinkList.vue`: a list with a heading is a `nav` named by that heading (`aria-labelledby`, an
  id unique on the page); a list without one is a `div`, so it adds no unnamed landmark. The list
  carries Utrecht's `--html-ul` modifier.
- `NlTable.vue`: the table sits in Utrecht's `utrecht-table-container`, which scrolls sideways on a
  phone.
- `tests/site-look/content-blocks.spec.mjs`, run by the new `check:site-look` script (every test
  under `tests/site-look/`), which is part of `check:specs`.

## Impact

- `css/site-theme.css`, `NlLinkList.vue`, `NlTable.vue`, `package.json` (one script), one test.
- A set that declares one of these Utrecht tokens keeps its value. Zuiddrecht's content page gets
  the same frame and cards from its own tokens.
- The thematiq bridge sets `--utrecht-alert-border-width: 2px` and the info border to the
  primary, so a melding keeps a 2px line where the boards draw none on the info card. That is the
  theme's choice and is left to thematiq.
