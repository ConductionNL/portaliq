## Why

Proof run 3 (09 Oct) put the four school portals beside their MijnMenu boards. The menu in the own
area was the most repeated difference: the boards' groups were missing, the page on screen was not
highlighted, a child's second line stood beside the name and broke it mid-word ("Hulstkam p"), the
counts were black, Vaartveld's person block ("Noor Bakker, 4 havo · klas H4b") had no way in, and
`/mijn/berichten` fell through to "Mijn zaken" with raw ids. Most of the groups are a learniq
declaration, but three things a portal cannot declare today:

- the board's word for an item ("Berichten" for the conversations page);
- a page listed once per row (the children) inside a declared group: the layout matched one row;
- a person block with a second line read from the portal's own data.

## What Changes

- Portal schema 0.14.0 (register 0.71.0), all additive:
  - `residentMenu.groups[].items[]` may be `{item, label}` besides a name; the menu shows the label.
  - `residentMenu.person`: `{collection: "app:id", fields: [...]}`. The menu opens with the initials,
    the session's name and the fields of that collection's first row joined by " · ". With an
    organisation card (`cardLabel`) the line goes under the organisation's name instead.
  - `residentMenu.routes`: `{"berichten": "messages"}`, a second address under `/mijn/` for an item.
- `PortalResidentMenu` projects the three keys with limits (label 60 characters, 4 fields, 20 addresses).
- `residentMenuGroups()`: a declared page that is listed per row places all its rows; a label renames
  an item, never a row.
- `accountRedirect()`: a second address opens its item; any other unknown `/mijn/...` address opens the
  home `/mijn`, never the first section (which a school portal leaves out of its menu).
- `ResidentMenu.vue` follows the boards: uppercase group labels, 44px items, the page on screen on the
  accent's light wash in the accent's text colour and bold, counts on the theme's badge colours, a
  row's second line under its name (no mid-word break), the person block, a tinted organisation card.
  Colours come from the theme's `--thematiq-accent-*` and `--thematiq-badge-*` roles only.

## For learniq

Each school portal declares `residentMenu.groups` with the board's groups (see tasks.md section 2 for
the exact keys), `residentMenu.routes`, and Vaartveld and the academy a `residentMenu.person`.

## Impact

- A portal that declares none of the new keys gets the same items and groups as before; only the
  look of the menu changes (wash instead of a side bar for the page on screen).
- An unknown address under `/mijn/` now opens the home instead of the first section.
