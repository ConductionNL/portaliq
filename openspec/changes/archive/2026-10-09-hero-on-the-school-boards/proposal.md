## Why

Proof run 2 (08 Oct) on the school homes:
- Esdoornveen's hero search label stood on a lilac strip. The vendored sheet paints every
  `.ac-search-box` in its light blue (the set's primary light), and the plain hero draws its form on
  the band. Zuiddrecht's hero ground hid it; Esdoornveen's white hero did not.
- The board draws that label semibold (Esdoornveen, Warmtepompacademie); the plain hero fixed it at
  regular, as Zuiddrecht draws it.
- The list beside the academy hero (hero-aside) reads the portal's catalogue and needs the
  portal's slug. The grid hands it to `nlEventList` placed on its own, but not through the hero, so
  learniq declared `portal` in the aside as a workaround.

## What Changes

- `css/site-theme.css`: the plain hero's search box has no background. Its label reads
  `--nldesign-website-hero-search-label-font-weight` and `-font-size` (regular and 18px when unset,
  as before).
- `WidgetGrid.vue` hands `portal` to the hero; `HeroBlock.vue` takes it and gives it to the block
  beside the text, after the authored props, as the grid does for the same blocks.
- `tests/site-look/hero-on-the-school-boards.spec.mjs`.

## Impact

- Zuiddrecht unchanged (its hero ground is the box's colour; no label token set). The academy and
  Esdoornveen sets name the label weight in thematiq. learniq can drop `portal` from the aside.
