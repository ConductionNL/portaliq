# Tasks: rows-status-note-per-state

- [x] 1. `DisplayKeys` keeps `statusNotes` on rows (projected `field`, projected `atField`, a template of at most 80 characters, at most 20 entries); the register's page block schema declares it (0.132.0).
  - Files: lib/Contribution/DisplayKeys.php, lib/Settings/portaliq_register.json, l10n/*
  - Test: tests/Unit/Contribution/DisplayKeysTest.php (testRowsKeepANotePerStatus, testStatusNotesAreCappedAtTwenty)
- [x] 2. The site draws a status's own note: `statusNoteOf()` picks the entry for the row's stored status value, `statusNoteInWords()` fills `{name}`, `{date}` and `{time}`, and `DateRows.vue` draws it.
  - Files: src/site/components/mijn/displays.js, src/site/components/mijn/rows.js, src/site/components/mijn/DateRows.vue
  - Test: tests/site-school-displays.spec.mjs ("a status with its own note reads its own field, moment and template")
- [ ] 3. Live: as a guardian on the wilgenboom Afwezigheid page, a submitted report seen by the teacher reads "Gezien door juf Esra om 8.12 uur" under "Ingediend", a decided one keeps "Meester Daan, 1 oktober 8:20 uur" (learniq live lane, wilgenboom.spec.ts).
