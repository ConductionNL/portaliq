# Proposal: rows-status-note-per-state

## Why

live-learniq-1 (25 Nov 2026, 19:23Z) found that the wilgenboom MijnLijst line "Gezien door juf Esra om 8.12 uur" never renders. learniq archived absence-reports-seen-and-ended T5/T7 with a per-state `statusNotes` on the `parentAbsence` rows block (while a report is `submitted`, the line names who saw it and when), but portaliq's rows display only knew one `statusNoteField`/`statusNoteAtField` pair: `DisplayKeys` dropped `statusNotes`, and `displays.js`/`DateRows.vue` never read it. The data is right (`seenBy` "Juf Esra", `seenAt` 07:12Z).

## What changes

- A `display: rows` block MAY declare `statusNotes`: a map from a stored status value to `{field, atField, template}`. A row whose status has an entry reads that entry's name field and moment, and draws the line with the template, which the contributing app translates itself (learniq's `PortalLabelTranslator` translates `template` keys). portaliq fills `{name}`, `{date}` (the day in words) and `{time}` ("8.12" in Dutch, "8:12" in English).
- Without a name the line is empty: nobody has seen the report yet.
- A status without an entry keeps `statusNoteField`/`statusNoteAtField` as before.
- `DisplayKeys` keeps `statusNotes` on rows only, an entry only when its `field` is projected, `atField` only when projected, `template` only when at most 80 characters, at most 20 entries.
- The register's page block schema declares `statusNotes` (register 0.132.0).
- A name field reads as a person's display name the same way any other field does: when the collection declares it `render: "user"` (learniq declares `seenBy` so).

## Out of scope

- Live check on the wilgenboom portal: the learniq live lane.
