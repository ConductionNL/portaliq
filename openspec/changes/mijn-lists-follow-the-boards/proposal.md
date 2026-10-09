## Why

Proof run 3 (09 Oct) showed the lists of the school portals as flat tables where the boards draw lists:

- Vaartveld's grades were a table of 25 rows; the MijnLijst board groups them per subject with the
  teacher, the marks as chips, the average as a large figure, an "Onder 5,5" pill, a chevron, period
  tabs and a summary ("6,7 gemiddeld over 8 vakken");
- rows and cards were not links (the academy's bookings, Wilgenboom's child cards), so a booking could
  not open its detail page;
- the academy's bookings had no tabs (Komend, Afgerond, Geannuleerd);
- the latest grades had no test line, no "Nieuw" pill and no large figure;
- dates read "2-10-2026" or "13-11-2025, 08:40:00";
- a list named like its page showed its heading twice ("Inschrijvingen").

## What Changes

- Any collection block may declare `rowPage` (a page of the same contribution) and `rowIdField`: each
  row, card or subject then links to `/mijn/<app>/<page>/<id>`; the title is the link, stretched over the
  row, with a chevron.
- Any collection block may declare `tabs` (2 to 6 `{label, field?, values?}`): a tab list over the rows,
  arrow keys move between tabs.
- `display: rows` may declare `valueField` (the large figure), `newField` (a "Nieuw" pill: true, or a date
  of the last seven days), `eyebrowField` (the small line above the title), `dateDisplay` (`tile`, `line`,
  `eyebrow` or `end`), `dateLabel` ("Geldig tot") and `rowStyle` (`cards` or `lines`).
- `display: chips` may group one row per mark by `groupField`, with `valueField`, `dateField`,
  `weightField` (weighted average), `subtitleField` (the teacher), `newField`, `summary` and
  `summaryText` (`{pass}`, `{fail}`, `{count}`). A subject without numbers shows its word and its first
  letter ("voldoende", "V").
- Table and text dates read in words: "2 oktober 2026", "13 november 2025, 08.40 uur".
- A display block named like its page shows no second heading.

## For learniq

The exact declarations are in tasks.md section 2.

## Impact

Additive keys. Every date in a table now reads in words. Stacked on #1426.
