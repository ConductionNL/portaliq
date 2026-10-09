## ADDED Requirements

### Requirement: An action form may ask for one entry of a day

An action's field configs MAY declare, and Portaliq SHALL keep only when they fit:

- a time input, for a field whose schema format is `time`;
- `computed: {kind: "duration", from, to, minus?}`, naming other fields of the same action. The site SHALL show the hours between the two times minus the pause in minutes as a line with the field's label, and SHALL send them as a number. The server SHALL work the value out again from the times it receives and SHALL drop a value that does not make a duration;
- `widget: "checks"` on a field with options, with `shown` (1 to 50). The site SHALL send the ticked values as a list in the order of the options. A `collection` options provider MAY name a `noteField`, shown above each option's label;
- `inline: true` on choice cards;
- `buttonLabel` on a file field.

An action MAY declare `footnote`, one line under its buttons. A create action MAY declare `saveForLater: {label, defaults}`. The site SHALL then offer a second button with that label. A request that says `saveForLater: true` SHALL be written with `saveForLater.defaults` over the action's own `defaults`. Its other values SHALL be checked like a sent entry.

#### Scenario: A student writes the hours of one day
- **GIVEN** an action with `startTime` and `stopTime` (format time), `pauseMinutes` as inline choice cards, `hours` computed from them, and `werkprocessen` as check cards with a code
- **WHEN** Milan fills in 08.00 and 16.30, picks "30 min" and ticks two work processes
- **THEN** the form says "Dat is vandaag 8 uur", and sends `hours: 8` and `werkprocessen` as a list of the two ids
- @e2e exclude covered by `tests/action-form-day-entry.spec.mjs` and PHPUnit `EntryFormKeysTest::testTheDayFormKeepsItsKeys`; learniq has no day record yet, so no live page exists

#### Scenario: A typed total never stands
- **GIVEN** the same action
- **WHEN** a request sends `startTime: 08:00`, `stopTime: 16:30`, `pauseMinutes: 30` and `hours: 40`
- **THEN** the record is written with `hours: 8`, and without `hours` when the stop is before the start
- @e2e exclude covered by PHPUnit `EntryFormKeysTest::testTheServerWorksTheHoursOutAgain`

#### Scenario: Saved for later, the entry waits
- **GIVEN** an action with `defaults: {lifecycle: submitted}` and `saveForLater: {label: "Bewaren en later versturen", defaults: {lifecycle: concept}}`
- **WHEN** Milan presses "Bewaren en later versturen"
- **THEN** the request says `saveForLater: true` and the record is written with `lifecycle: concept`, whatever the request sent for it
- @e2e exclude covered by `tests/action-form-day-entry.spec.mjs` and PHPUnit `EntryFormKeysTest::testSavedForLaterTheEntryWaits`

#### Scenario: What does not fit is dropped
- **GIVEN** check cards on a field without options, a computed value that reads a field the action does not have or itself, and a save for later without defaults or on an update action
- **WHEN** the manifest is normalised
- **THEN** each of those keys is gone and the field keeps its plain input
- @e2e exclude covered by PHPUnit `EntryFormKeysTest::testWhatDoesNotFitIsDropped`
