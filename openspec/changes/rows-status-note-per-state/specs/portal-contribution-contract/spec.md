# portal-contribution-contract Delta: rows-status-note-per-state

## ADDED Requirements

### Requirement: A dated row may say a different note per status

A collection block with `display: rows` MAY declare `statusNotes`, a map from a stored status value to `{field, atField, template}`. A row whose `statusField` value has an entry MUST read the line under its status from that entry: the `field` value as `{name}`, the `atField` moment as `{date}` (the day in words) and `{time}` (the time of day, "8.12" in Dutch, "8:12" in English), filled into `template`, which the contributing app translates. When the entry's `field` is empty the line MUST be empty. A row whose status has no entry MUST keep the `statusNoteField`/`statusNoteAtField` line. The normaliser MUST keep `statusNotes` on rows only, keep an entry only when its `field` is a field the collection projects, keep `atField` only when projected and `template` only when it is at most 80 characters, and keep at most 20 entries.

#### Scenario: A submitted report says who saw it
- GIVEN Vera's absence report with status "submitted", `seenBy` "Juf Esra" and `seenAt` 5 October 2026 08:12
- AND the rows block declares `statusNotes: {submitted: {field: seenBy, atField: seenAt, template: "Gezien door {name} om {time} uur"}}` beside `statusNoteField: decidedBy`
- WHEN the guardian opens Afwezigheid
- THEN the row reads "Ingediend" with "Gezien door Juf Esra om 8.12 uur" under it
- AND an approved report still reads "Meester Daan, 1 oktober 8:20 uur"
- @e2e exclude pure function, covered by `node --test tests/site-school-displays.spec.mjs` ("a status with its own note reads its own field, moment and template") and PHPUnit `DisplayKeysTest::testRowsKeepANotePerStatus`; the live run is learniq's wilgenboom.spec.ts

#### Scenario: Nobody has seen the report yet
- GIVEN a submitted report with an empty `seenBy`
- WHEN the guardian opens Afwezigheid
- THEN no line stands under "Ingediend"
- @e2e exclude pure function, covered by `node --test tests/site-school-displays.spec.mjs`
