## Why

The fresh install of the four school portals (proof run 1, 06 Oct, item 6, portaliq half) showed
the Esdoornveen and Warmtepompacademie home pages without a hero heading. Their hero declares a
search box, and `HeroBlock` kept `CnSiteHero`'s rule: with a search box the heading and the lead
become `sr-only`, because the box was labelled with the heading. Every design (the four schools
and Zuiddrecht) draws heading, lead and search box together. Zuiddrecht worked around it with
`headingVisible: true` on its placement.

The proof also showed the hero lead at body size on one long line. The plain hero variant
(`variant: plain`, site-matches-the-zuiddrecht-boards) already sets the heading at the board size;
its lead had no rule.

## What Changes

- `HeroBlock.vue`: the heading and the lead show unless the author sets `headingVisible: false`.
  The search box is named by the author's `searchLabel` (visible); without one, by the hidden
  heading when the author hid it (the old band, visible label), else by the button's word for
  screen readers only, so a visible heading is not read out twice.
- `css/site-theme.css`: the plain hero's lead is 20px on a line of at most 40rem
  (`--nldesign-website-hero-lead-size` to change the size).
- `tests/site-look/hero-heading.spec.mjs`.

## Not in this change

- The banner the boards draw as a coloured "Let op" strip with a link exists on `development` as
  options of `nlBanner` (`kind: notice`, `lead`, `linkLabel`, `linkHref`, `band`). The school
  portals get it when learniq's portal files use those options and drop `closable` (lane L3).
- The hero heading at the board size is the plain variant: learniq's school heroes need
  `variant: plain` (lane L3).

## Impact

- `HeroBlock.vue`, `css/site-theme.css`, one test. Zuiddrecht's `headingVisible: true` keeps
  working and is no longer needed.
