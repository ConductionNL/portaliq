# Proposal: list-row-opens-its-record-page

## Why

live-learniq-1 (25 Nov 2026, 20:08Z): on the external assessor's and the trainer's overview, the "Met u gedeeld" rows never link to the share's own page. learniq lists `eaSharedPortfolios` on the overview and declares `eaShare` as that collection's record page, out of the menu (`menu: false`). portaliq opens a record on its record page when a message, task or case card links to it (REQ-SMO-010), but a plain list row on another page only marks the row as chosen, so the share page is unreachable.

## What changes

- Choosing a row of a list opens it on its collection's record page when that is another page of the same app (the route `<record page>/<id>`), instead of only marking it.
- A page that shows the row itself keeps it: the collection's own record page, and a page with a `detail` or `citizenCase` block on that collection.
- A page that also lists the collection is not a record page to open, as REQ-SMO-010 already says.

No contract key is added: the record page a contributing app already declares is what the row opens.
