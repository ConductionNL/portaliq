# Tasks: action-form-day-entry

- [x] 1. `format: time` is a time input (`SchemaInputHintNormaliser`, `fieldInput`).
  - PHPUnit `EntryFormKeysTest::testTheDayFormKeepsItsKeys`; `npm run check:action-form-day-entry`
- [x] 2. `computed` duration: kept only when it reads other fields of the action; shown as a line and sent as a number; worked out again on the server.
  - PHPUnit `EntryFormKeysTest::testWhatDoesNotFitIsDropped`, `::testTheServerWorksTheHoursOutAgain`; `tests/action-form-day-entry.spec.mjs`
- [x] 3. `widget: checks` with `shown`, and `noteField` on a collection options provider.
  - PHPUnit `EntryFormKeysTest::testTheDayFormKeepsItsKeys`; `tests/action-form-day-entry.spec.mjs`
- [x] 4. `inline` choice cards, `buttonLabel` on a file field, `footnote` on an action.
  - PHPUnit `EntryFormKeysTest`; `tests/action-form-day-entry.spec.mjs`
- [x] 5. `saveForLater` on a create: a second button; the server stamps its defaults.
  - PHPUnit `EntryFormKeysTest::testSavedForLaterTheEntryWaits`; `tests/action-form-day-entry.spec.mjs`
- [ ] 6. learniq adds the `bpv-hour-day` schema and declares `writeHourDay` (FIX-L).
