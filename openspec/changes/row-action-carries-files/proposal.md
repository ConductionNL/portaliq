# Proposal: an endpoint row action may carry the files the resident adds

## Why

A row action forwarded only its whitelisted text fields. dossiq's Woo requester answers the question on their request through the row action `beantwoordVraag`, and the board ZaakWooVerzoek draws a "Bestanden toevoegen" button beside that answer. Without files the answer could not carry the document it was about, so dossiq's spec had to drop attachments (woo-dossier-shared-with-the-requester REQ-WDS-001).

## What changes

- An endpoint row action may declare `files: {field, max?, maxBytes?}`. The normaliser keeps it only on an endpoint row action, only with a plain field name that is not a text field and not the row field, and bounds `max` (default 5, at most 10) and `maxBytes` (default 10 MiB, at most 25 MiB).
- The confirmation dialog shows a file input under the board's label "Bestanden toevoegen" and checks the number and size before it sends.
- The row-scoped forward reads the uploads under the declared field, refuses too many or too large with 422 before the audit and the forward, and forwards them multipart beside the fields, each file as `<field>[]`.
- An action without `files` forwards JSON exactly as before; uploads sent to it are never read.

## Consumers

dossiq `woo-dossier-shared-with-the-requester` (REQ-WDS-001): the requester's answer files its attachments on the case as incoming documents.
