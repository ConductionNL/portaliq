## Why

Two school boards put something beside the hero text (school portal proof, 06 Oct, "Visual"):
- Warmtepompacademie's home has the card "Eerstvolgende cursusdagen", a list of course days with a
  date tile, the title as a link, the place and the seats.
- Esdoornveen's home has a photo with one slanted corner.

The hero block could only stack its parts in one column, so both pieces stood under the hero or
not at all. learniq's FIX-L lane left this to portaliq (learniq #1839, "Not in this change").

## What Changes

- `HeroBlock.vue` takes two optional props:
  - `aside: {widgetKey, props}`: a list block shown in a card beside the text. One of
    `nlEventList`, `nlLinkList` or `nlNewsList`, loaded on demand through the widget loaders.
    Any other key is ignored, so a hero never holds another band.
  - `asideImage: {src, alt}`: a photo, with an address on this site or `https:`, any other address
    ignored. Or `{label}`: a tinted plane with words, for a design that marks where a photo goes.
- `css/site-theme.css`: with an aside, the band is two columns (text, then the aside up to 32rem),
  one column at 768px and below. The aside card takes the set's background, large radius and card
  shadow; the photo the large radius and the token `--nldesign-hero-image-clip-path` (plan
  deviation D-3), none by default. The emblem watermark steps aside. Without an aside the text's
  wrapper is `display: contents`, so every other hero lays out as before.
- `tests/site-look/hero-aside.spec.mjs`; the pixel baseline of the shipped demo hero carries the
  new wrapper.

## For the other lanes

- learniq (FIX-L): the academy hero declares `aside: {widgetKey: "nlEventList", props: {heading:
  "Eerstvolgende cursusdagen", display: "tiles", items: [...], moreLabel, moreHref}}`; the
  Esdoornveen hero declares `asideImage` (`src` and `alt` for a photo, or `label` for the marked
  plane).
- The theme: Esdoornveen's set may name `--nldesign-hero-image-clip-path` with the board's
  polygon for the slanted corner.

## Impact

- `HeroBlock.vue`, `css/site-theme.css`, one test, one baseline. No server change: page widget
  props reach the hero as authored.
