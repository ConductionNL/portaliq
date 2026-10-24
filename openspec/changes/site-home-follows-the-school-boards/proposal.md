## Why

Proof run 3 (9 October) put each school's home page and footer beside the boards Home and Voet:

- the footer underlines the whole line "E-mail: [e-mailadres]", where every board links only the
  address;
- Vaartveld's hero draws its second action "Lees wat er speelt op school" as an underlined text
  link beside the button, and the button "Kom kennismaken" with a chevron; the hero has only
  outlined buttons;
- the academy's boards write the month under a date tile in capitals ("OKT");
- the academy's hero aside draws two frames: the aside card and, inside it, the framed course list.

## What Changes

- `src/site/lib/footerLine.js` `contactLineParts()`; `FooterColumns.vue` links only the part after
  "Label: " of a contact line with an address. A line without a label links as a whole, as before.
- `heroActions()` keeps `style: "link"` and `chevron: true`; `HeroBlock.vue` draws a link action
  underlined without a box, and a chevron after a button's label.
- `css/site-theme.css`: the date tile's month reads `--thematiq-date-month-text-transform` (thematiq
  `school-sets-type-scale`); a framed event list inside the hero aside card loses its own frame.
- `tests/site-look/home-boards.spec.mjs`.

## What learniq declares (lane L3)

- vo home `hero.props.actions`: `[{"label": "Kom kennismaken", "href": "/aanmelden", "chevron":
  true}, {"label": "Lees wat er speelt op school", "href": "/zoeken", "style": "link"}]`; the same
  shape where another board draws a text link.
- "Direct regelen" icons as the boards draw them: `nlQuickTasks.props.iconStyle: "plain"` (the
  option exists), and the icon names per tile the boards use.

## Not in this change

- The gap between the "Direct regelen" card and the news, the content column width, card heights
  and the "Verloopt er een certificaat" section's top padding are layout of the grid and its bands.
- The Direct regelen card's border instead of a shadow is a theme value
  (`--nldesign-component-content-card-border-color`, `--nldesign-component-content-card-shadow-color`).
