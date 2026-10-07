# Proposal: citizen-case-shows-only-its-fields

## Why

Seen on the dossiq Woo flow on the site (2026-10-02, dossiq#3247). A resident opened their Woo request from "Mijn zaken" and the case screen listed about a hundred fields: uuids, the assignee, the priority, the quality score. The server sent the whole case row to the resident's browser, and the screen printed every key with "Dit antwoord kunt u niet vanuit het portaal wijzigen." under it.

The same visit showed three smaller faults. "Mijn zaken" showed the status as a uuid. "This request has already been withdrawn." read in English. With no case chosen, the page asked twice: "Kies een item." and "Kies een zaak.".

## What changes

- The case screen's server answer (`show`, and the case `amend` and `withdraw` return) is projected to the `fields` the contribution's collection on that register and schema declares. The screen's own fields travel with it: the answers the action may write, the fields the writable set names, the status field and `withdrawnAt` / `withdrawalReason`. Nothing else leaves the server. The full row stays on the server, where the writable set and the withdrawal are worked out. A collection that declares no `fields` passes the row whole, as its list does. A malformed declaration projects to the identifiers.
- The case screen lists only the answers the writable set names. The case number, status and dates belong to the detail card.
- A `cases` collection MAY declare `statusLabelField`: the field that holds the words for the status. "Mijn zaken" shows those words and keeps the raw status for logic. It is kept only when it names a projected field, the `closedField` rule.
- The three withdrawal sentences get nl, en and en_US catalogue entries.
- A `citizenCase` block that shares its collection with a `detail` block on the same page says nothing until a case is chosen.

## Not changed

- What a resident may write, and how the write is checked.
- The mandated read-only screen, which already reads rows projected by the collection.
- The React portal (`src/portal`), which is being retired.
