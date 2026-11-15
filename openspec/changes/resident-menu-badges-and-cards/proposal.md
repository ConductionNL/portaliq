## Why

The resident menu of the school designs (MijnMenu board, all four schools) shows each child as an
entry with a second line ("Vera, Groep 7 · Meester Daan"), counts beside entries ("Berichten 2",
"Oudergesprekken 1"), the total on the phone button ("Mijn Wilgenboom, 2 nieuw"), the page on
screen on the accent's light wash, and for an employer a card at the top ("U regelt het voor,
Jansen Installatietechniek BV"). The menu could show a count for the inbox only, listed a
per-record page as a group per row, and had no card. Gaps G-05 and G-06 of the school portal plan.
Follows `site-resident-menu` and `site-mijn-omgeving-components` REQ-SMO-006 and REQ-SMO-020.

## What Changes

- A `perRecord` page that declares `group` lists each row as an item of that group, named by the
  row's title fields, with its `records.subtitleFields` joined by " · " as a second line. Without
  `group` the menu keeps a group per row.
- A page may declare `badge: {collection, label?}`: the menu loads that collection with the
  per-record ones and shows the number of rows as a count, `label` (with `{count}`) for screen
  readers. The server keeps the key only once lane L2 adds it to `PageMenuKeys` (requested).
- The phone button shows the portal's `accountLabel` and the total of the counts ("2 nieuw").
- Portal key `residentMenu.cardLabel`: while the session acts for an organisation
  (`session.organisationName`) the menu opens with a card: the label and the organisation's name.
- Styling in `css/site-theme.css`: capital group names, the current entry on
  `--thematiq-accent-light-color` with a bar in `--thematiq-accent-color`, counts in
  `--thematiq-badge-*`.

## Impact

Additive. Menus of portals and contributions that declare none of the keys render as before apart
from styling. Stacked on `site-chrome-follows-the-design` (same branch base); merge that first.
