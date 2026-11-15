## Why

The fresh install of the four school portals (proof run 1, 06 Oct, item 9) showed content blocks
that did not look like the boards. On `development` (after the Zuiddrecht board rounds #1251 and
#1258 added opt-in displays for tables and link lists) two defects remain that no opt-in covers:

- every melding (`nlAlert`) renders as text flush against a coloured line, with no room inside and
  no tint, where the boards draw a card ("Online melden", "Liever bellen?"). The Utrecht alert reads
  its padding and backgrounds from `--utrecht-alert-*` tokens no shipped set declares;
- three link lists on one home page are three `nav` landmarks without a name (axe
  `landmark-unique`, every school home page). The proof build also had link targets 19px high
  (axe `target-size`, serious).

## What Changes

- `css/site-theme.css`: a melding gets padding, the tint of its kind (info the set's primary light;
  ok, warning and error the set's status badge background for that kind) and a gap between heading
  and text, and fills its grid cell so two meldingen side by side are one height. A link in a list
  is at least 24px high. Every value reads the Utrecht token first and ends in a keyword. No literal
  colours.
- `NlLinkList.vue`: a list with a heading is a `nav` named by that heading (`aria-labelledby`, an id
  unique on the page); a list without one is a `div`, so it adds no unnamed landmark. The displays
  (`plain`, `card`, `tinted`, `accent`) are unchanged.
- `tests/site-look/content-blocks.spec.mjs`, run by the new `check:site-look` script (every test
  under `tests/site-look/`), which is part of `check:specs`.

## Not in this change

- The designed table frame and the accent line above "Meer over praktische zaken" exist as opt-ins
  (`nlTable` `display: boxed` with `captionVisible: false`, `nlLinkList` `display: accent`). The
  school portals get them when learniq's portal files ask for them (lane L3).
- The thematiq bridge sets `--utrecht-alert-border-width: 2px` and the info border to the primary,
  so a melding keeps a 2px line where the boards draw none on the info card. That is the theme's
  choice and is left to thematiq.

## Impact

- `css/site-theme.css`, `NlLinkList.vue`, `package.json` (one script), one test.
- A set that declares one of the Utrecht alert tokens keeps its value.
