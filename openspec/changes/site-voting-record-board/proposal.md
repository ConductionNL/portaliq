# site-voting-record-board

## Why

The PtStemgedrag board draws a council member's voting record with the page's help under the intro, a table with a
tinted header row and 12px 16px cells, and a side column topped by a 4px accent rule holding the member, the figures as
a plain list (label left, number right in bold) and the links. Live, the side column was a bordered box with each
figure as its own card, the table drew 1px cells, and the page carried no help.

## What changes

- A public record's side column has no box: a 4px accent rule on top, the figures as a two-column list, the links
  without bullets.
- The record's table takes the board's cells: a tinted header row, 12px 16px padding, a hairline between rows.
- The example site gives "Hoe stemden de raadsleden" its "Hulp bij deze pagina" text.

The record's heading standing in for the page title (the board's H1 "Stemgedrag van …") and the record's filters (which
need the provider to answer filter options) are not part of this change.
