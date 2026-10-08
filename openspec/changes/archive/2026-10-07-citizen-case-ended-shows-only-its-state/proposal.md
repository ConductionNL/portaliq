# Proposal: citizen-case-ended-shows-only-its-state

## Why

Seen live on the site on 2026-10-02 (portaliq development 9c0e0f94, dossiq cda2aa694). Resident Sanne withdrew a Woo request and opened it again. Above "Ingetrokken op 02-10-2026." the screen said "Uw verzoek is al in behandeling. Wilt u iets aanvullen? Stuur ons een bericht." and "Wij beoordelen de documenten al. Stuur ons een bericht als u nog iets heeft."

Those are the Woo case type's sentences for a window that has closed while the request runs. The writable set sent them for every status outside the window, so a withdrawn request invited the resident to add to it. A case staff had closed did the same.

## What changes

- The writable set says whether the case has ended (`ended`). A case has ended when it carries `withdrawnAt`, or when the `closedField` of its own collection holds a value. That is the marker "My cases" files it under Closed by. `CitizenWriteActionFinder` hands the marker over beside the collection's `fields`.
- On an ended case every window is closed, nothing is writable, and every reason is one neutral sentence: "This case is not open for changes from the portal." A refused write answers with it. The withdrawal is closed too, even in a status the case type lists as open.
- The case screen shows an ended case's status, its answers and its withdrawal. It shows no sentence about a closed window, a closed document window, a closed withdrawal or a field that cannot change.

## Not changed

- What a running case offers, and the case type's sentences on it.
- The mandated read-only screen.
