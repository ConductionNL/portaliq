# Proposal: mandated-case-offers-no-writes

## Why

Seen in the Zuiddrecht go-live rehearsal: Linda Bakker, acting for her father Henk, opened his case on its record page and was offered the add button on Stukken, "Bericht sturen", "Bezwaar maken" and "Klacht indienen". The server refuses every one of them: a case read under a mandate is read-only (REQ-CMC-004), and the case screen answers a closed window. Only the `citizenCase` block followed that; the other blocks of the page did not know the record was read under a mandate.

A mandate (`portalMandate`) carries no grant to act: it names the party, the reach and the case types. So under any mandate the case is read, not changed.

## What changes

- A record read under a mandate (`_mandate.id`) is shown without its writes: the forms and buttons bound to the record (`withRecord`), the documents block's add button and the detail card's propose-change action are left out.
- What the page reads (steps, details, documents, history, the case screen's notice) stays.

## Not changed

- The server's refusal of a write under a mandate.
- A form on the page that is not bound to the record.
