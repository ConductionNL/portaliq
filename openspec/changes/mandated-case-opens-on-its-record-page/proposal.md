# Proposal: mandated-case-opens-on-its-record-page

## Why

Seen in the Zuiddrecht go-live rehearsal R2: Linda Bakker acts for her father Henk. "Mijn zaken" lists Henk's case, but opening it showed an empty "Uw zaak". The case page is a record page over dossiq's `mijnZaken`, and a record page opened only a row from the resident's own list. Henk's case is never in Linda's own list, so nothing opened, although the list had carried the row and the case block already reads a row under its `_mandate`.

## What changes

- A record page opens the picked row when it is in the resident's own list, or when it was read under a mandate (it carries `_mandate.id`). The blocks on the page read it under that mandate, as the case block already does.
- A record page whose route names the record keeps the row a record link carried for the same id.
- The steps, the history and the documents of such a case are read under the mandate its row carries: the steps and timeline routes take `mandate` and prove the case through the same reader that lists it on "My cases"; the documents come from the read-only case screen, which lists none under a mandate.

## Not changed

- The server: the case screen still reads a mandated case through the same reader that lists it (REQ-CMC-004), read-only.
- A row outside the resident's list without a mandate still opens nothing.
