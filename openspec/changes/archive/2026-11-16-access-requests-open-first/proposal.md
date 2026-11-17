# access-requests-open-first

## Why

The PtToegangsverzoeken board lists the open requests on top, tinted, then the decided ones, and draws "Gevraagde
toegang" on two lines: a muted "Zaken van" above the organisation. Live listed them in storage order, and OpenRegister
can only sort a field alphabetically, which puts `granted` before `pending`.

## What changes

- An access request carries `stateRank`: 0 while pending, 1 when granted, 2 when refused. The schema's default is 0, so
  a request recorded from the list (a new one is pending) gets it without code. Every place that writes `state` writes
  the rank with it, and a repair step backfills the requests that have none.
- The Access requests list sorts on the server by `stateRank`, then newest first, so open requests come first on every
  page of the list, not only the loaded one.
- "Gevraagde toegang" reads "Zaken van" above the organisation, through nextcloud-vue's column overline
  (table-cell-overline).
