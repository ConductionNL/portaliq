## Why

Proof run 3 (9 October) put each school's content page beside its board Contentpagina ("Uw kind
afwezig melden", "Ziek melden en verlof", "Ziek melden en beter melden", "Medewerkers inschrijven").
The blocks cannot draw what the boards draw:

- the button belongs inside its callout ("Afwezig melden" inside "Online melden", with a chevron;
  on Esdoornveen beside the words of a callout without a heading); the melding has no button;
- "Liever bellen?" is a white card with a line and the number in bold; a melding is always tinted
  and its text plain;
- the warning callout ("Is uw kind afwezig zonder melding?") has a triangle before the words;
- Esdoornveen's steps are compact: a small filled circle with the number, and only the lead phrase
  ("Meld je ziek op de eerste dag,") bold; `display: steps` draws large numbers between lines with
  the whole step bold;
- every board draws the first column of a table bold.

## What Changes

- `nlAlert`: `action: {label, href}` renders a primary button link with a chevron inside the melding
  (under the words, or beside them when there is no heading); `kind: plain` is a white card with a
  hairline; words between `**` show in bold (never HTML); `warning` and `error` carry a mark.
  Logic in `nlAlert/alert.js`.
- `nlList`: `display: numbered`, compact numbered steps in the primary colour with the lead phrase in
  bold (`stepLead()` in `lines.js`).
- `nlTable`: `rowHeaders`, the first cell of each row a bold `th scope="row"`.
- `tests/site-look/callouts-steps-tables.spec.mjs`; `nlAlert` joins the widgets that draw their own
  styles in `tests/widget-tokens.spec.mjs`.

## What learniq declares (lane L3)

In `lib/Settings/portals/*.json`, on the content pages:

- po `/praktisch/afwezig-melden`: on "Online melden" `action: {"label": "Afwezig melden", "href":
  "/mijn"}` (and drop the separate `nlButtonLink`); "Liever bellen?" `kind: "plain"` with the number
  as `**[telefoonnummer]**`; the closing paragraph as an `nlAlert` `kind: "warning"` instead of the
  last markdown paragraph; `nlTable.rowHeaders: true`; the link list from `gridY 0` spanning the rows.
- vo `/praktisch/ziek-melden`: the "Melden duurt een minuut" melding with `action` "Ziek melden";
  "18 jaar of ouder?" and "Is uw kind lang ziek?" as `nlAlert` `kind: "plain"` at `gridX 8` under
  the link list (now markdown in the main column); `rowHeaders: true`.
- mbo `/voor-studenten/ziek-melden`: an `nlAlert` without heading, text "Het kan vanaf je telefoon
  en kost een halve minuut." and `action` "Inloggen en ziek melden"; a "Zo werkt het" heading;
  `nlList.display: "numbered"`; "Ben je jonger dan 18?" as markdown (plain on the board); "Lukt
  inloggen niet?" as `kind: "plain"` at `gridX 8`; the link list `display: "tinted"`;
  `rowHeaders: true`.
- training `/voor-werkgevers/medewerkers-inschrijven`: "Betalen" and "Overleggen met de planning"
  as `kind: "plain"` cards at `gridX 8`; `rowHeaders: true`.

## Not in this change

The breadcrumb's words ("Ziek melden" where the page title is longer) are the portal's choice:
`site-breadcrumb-follows-the-school-boards`.
