# Proposal: inbox-delete-own-messages

## Why

Seen in the parent portal review of 2026-10-03: a resident cannot remove a message from "Berichten". Test runs leave dozens of notices in a guardian's inbox, and a real resident has no way to tidy up either.

## What changes

- `DELETE /portal/api/inbox/{register}/{schema}/{id}` deletes one of the resident's own inbox messages. It uses the same guard as mark-read: the (register, schema) must be an inbox the resident may read, and the trust level is checked again.
- Portaliq's own notices (`portalMessage`) may always be deleted. A message in an app's inbox is that app's record, so it may be deleted only when the collection declares `deletable: true`. Portaliq's normaliser keeps that flag as a strict boolean.
- `PortalObjectWriter::deleteObject()` reads the row again and deletes it only when it is the resident's alone: the scope field holds the resident's own reference (or a list of only that reference) and the tenant matches. Another resident's message, a message shared with someone else and an unknown id all answer the same 404, and nothing is deleted.
- Each inbox row carries `_source.deletable` when the resident may delete it.
- On the Berichten page a deletable row has a "Verwijderen" button and a checkbox; "Alles selecteren" and "Geselecteerde verwijderen (n)" delete several at once. Before anything is deleted the page asks "Dit bericht verwijderen? Dit kunt u niet ongedaan maken." (or "n berichten verwijderen?") with "Ja, verwijderen" and "Annuleren", on the page itself, not in a browser dialog. Afterwards a status line says what was deleted; a message the server refused stays and stays chosen, with "Niet elk bericht kon worden verwijderd. Probeer het opnieuw."

## Not changed

- What a resident may read, and mark-read.
- An app's inbox that does not declare `deletable` offers no delete.
