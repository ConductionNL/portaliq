## Why

Proof run 3 (09 Oct): on a phone the own area of the school portals wore the Zuiddrecht chrome, not the
MobielHome boards'. The header said "Uitloggen" where the boards show the person's initials; a "‹ Home"
crumb stood above the page; about 180px of empty room stood above the footer; and the full footer (brand
column, contact, two menus) closed every page where the boards draw a short one: the logo, one line and two
links.

## What Changes

- Portal schema 0.17.0 (register 0.74.0), additive:
  - `residentMenu.phoneHeader: "person"`: inside the own area on a phone the header shows the person's
    initials instead of "Uitloggen", "Uitloggen" moves to the end of the resident menu, the crumb trail is left
    out, and the room under the page shrinks to 24px;
  - `footer.compact` `{text, links}` (at most four links): the short footer the own area shows on a phone.
- `AccountArea` marks a compact own area; its phone rules swap the header chip, the crumb, the footer.
- `CompactFooter` draws the short footer, loaded on demand.
- `ResidentMenu` ends in a sign-out button on a phone when the header shows the person.

## For learniq

Each school portal: `"residentMenu": {"phoneHeader": "person"}` and `"footer": {"compact": {"text":
"Telefoon: [telefoonnummer]", "links": [{"label": "Toegankelijkheid", "href": "/toegankelijkheid"}, {"label":
"Privacy", "href": "/privacy"}]}}` (esdoornveen: "Vraag over een student? Bel BPV-begeleider Ruud Hermans:
[telefoonnummer]").

## Impact

Nothing changes for a portal that declares neither key (Zuiddrecht keeps "Uitloggen" on MobielZaak).
Stacked on #1432.
