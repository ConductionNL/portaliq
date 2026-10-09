## Why

Proof run 3 (09 Oct) put the four school overviews beside their MijnOverzicht boards. The blocks were
the right ones, but the page did not look like the boards:

- every block stood in one column, where the boards put the timetable beside homework and grades
  (Vaartveld) and the news beside "Deze maand" (De Wilgenboom);
- no block had the board's card frame or the grey strip ("Afwezigheid dit schooljaar");
- "Hele week", "Alle inschrijvingen" and "Alle certificaten" were bordered buttons under a block,
  where the boards draw a link at the end of the block's heading row;
- the pills were outlined, where every board draws tinted pills;
- the date line had no week number ("Maandag 5 oktober 2026 · week 41");
- the e-mail prompt spoke to pupils in the formal "u" and stood on boards that have none.

## What Changes

- Any block of a contributed page may carry `column` (`main` or `side`), `frame` (`line` or `tinted`,
  `true` reads as `line`) and `more` (`{label, page|route, placement}`; `placement: "end"` puts the link
  under the block, else it stands at the end of the heading row). `BlockLayoutKeys` keeps them on every
  block that survives the normaliser; a key that does not fit is dropped.
- The site places the blocks in a grid of a main and a side column from tablet width: consecutive
  blocks with a column form one band, each column stacking from the band's first row. A block without
  a column spans the page. A phone keeps the declared order in one column. A page that declares none of
  these keys renders the exact markup it had (`BlockShell` draws no element then).
- `greeting` may declare `showWeek: true`: "Maandag 5 oktober 2026 · week 41" (ISO week).
- `kpi` may declare `display: "strip"`: the heading and the figures on one line, each figure followed
  by its card's `stripLabel` ("1 dag ziek").
- Data badges are tinted: no outline unless the theme names `--nl-data-badge-border-color`.
- Portal schema 0.15.0 (register 0.72.0): `contactPrompt` `{show, text, button, dismiss}`. `show: false`
  leaves the e-mail prompt out; the texts replace the site's words, so a pupil portal can say "je".

## For learniq

The exact declarations per overview are in tasks.md section 2.

## Impact

- Additive keys only. A page without them is unchanged, except that every data badge loses its
  outline.
- Depends on portaliq #1423 (register 0.71.0); this PR is stacked on it.
