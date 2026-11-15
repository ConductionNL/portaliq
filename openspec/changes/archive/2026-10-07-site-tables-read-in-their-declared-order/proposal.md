# Proposal: site-tables-read-in-their-declared-order

## Why

Seen on the Wilgenboom parent portal (2026-10-05), on learniq's page of a child's absences and on the signed-out welcome page.

The absences read 1 October, 5 October, 2 October, 25 September. A collection may declare `defaultSort`, and the normaliser keeps it, but nothing in the site read it. Only a block's own `sort` ordered a table, and a table per child (`groupByField`) ignored that too.

Under the list stood "Kies een item." while no row was chosen. The table's first column already opens a row, so the line read as a stray one.

On the welcome page the second "Inloggen met DigiD" was browser blue, rgb(0, 0, 238), on a themed portal. The link carries the `utrecht-button-link` classes, and no stylesheet in the site defined them: `@utrecht/button-link-css` was only present as a dependency of a dependency and was never imported.

## What changes

- A table reads in the block's `sort`, else in its collection's `defaultSort`, else in the order the rows arrived. A table per child reads in that order inside each group.
- A `detail` block under the table of its own collection says nothing until a row is chosen. A card without that table on the page still says "Select an item.". So does the card a quiet case screen leans on, so "Mijn zaken" keeps asking once.
- `@utrecht/button-link-css` becomes a dependency of its own, and the two components that use its classes import it. The sign-in link on the welcome page and the start link of an external form draw as the theme's primary button.

## Not changed

- What the server reads, and the order it reads in. The order is applied to the rows the site already has, as a block's `sort` is.
- The React portal (`src/portal`), which is being retired.
- The contract: `defaultSort` was already a documented and normalised collection key.

## Depends on

Nothing. learniq declares `defaultSort` on `parentExcuseRequests` in a change of its own; until it lands, that list keeps the order it has.
