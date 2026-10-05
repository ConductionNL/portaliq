## ADDED Requirements

### Requirement: A collection block may draw its rows as dated rows, bars, chips or richer cards

A `collection` block MAY declare `display` as `rows`, `bars` or `chips`, and a `cards` block MAY declare more parts. Every field a display names MUST be one its collection projects; a field that is not MUST be dropped and the display MUST draw without it. An unknown display MUST add nothing. A value MUST read as the collection's word for it (`fieldConfigs.<field>.valueLabels`) when it has one.

- `rows`: `dateField` (a date tile), `titleFields` (joined with " · "), `subtitleField`, `quoteField` (in quotes), `statusField` (a pill) with `statusTones` (value to `neutral`, `success`, `warning` or `error`) and `statusNoteField` (the line under the pill).
- `bars`: `labelField`, `valueField` against `max` (default 10), the figure as text and the bar decorative; `captionField` and `noteField` with `noteLabel` read from the first row.
- `chips`: `labelField`, `valuesField` (a list of marks), `averageField`, and `lowBelow`; a mark or average below it MUST be marked in words for assistive technology as well as in tint.
- `cards`: besides `titleFields` and `progress`, `subtitleFields`, `statusField` with `statusTones`, `noteField`, `soonField` with `soonLabel`, and `avatar`.

#### Scenario: An absence list as dated rows
- GIVEN a collection of excuse requests and a block with `display: rows`, `dateField: date`, `titleFields: [child, reason]` and `statusField: status`
- WHEN the page renders
- THEN each row shows a date tile, "Sami · Ziek" and the status in words

#### Scenario: A field the collection does not project
- GIVEN a `rows` block whose `subtitleField` the collection does not project
- WHEN the manifest is normalised
- THEN `subtitleField` is dropped and the rows draw without a sub line

#### Scenario: A mark below the pass mark
- GIVEN a `chips` block with `lowBelow: 5.5` and a mark of 4,7
- WHEN it renders
- THEN that chip has the warning tint and its text includes "onvoldoende" for a screen reader

### Requirement: The overview blocks may take the school displays

A `tasks` block MAY declare `display: highlight` with `eyebrow`, `subtitleFields` and `buttonLabel`: each row MUST then be a card on the accent-light ground with the small uppercase label and one button that opens the row, never a coloured edge alone. A `kpi` block MAY declare `display: segmented` with up to five `segments` (`field`, `label`, `tone`: `positive`, `waiting` or `warning`), `totalField` or `target`, and `unit`; it MAY then stand without cards. The figure MUST say each part in words with its number; the bar is decorative. A `calendar` block MAY declare `display: tiles` and a source MAY name a `metaField`; the items from today MUST then be drawn as date tiles with their title and meta line.

#### Scenario: Hours as one segmented bar
- GIVEN a row with 96 approved, 16 waiting and 8 returned hours and `target: 480`
- WHEN the kpi block renders
- THEN it reads "96 van 480 uur" and lists each part with its number

#### Scenario: The task to do first
- GIVEN a tasks block with `display: highlight`, `eyebrow: "Eerst dit"` and `buttonLabel: "Tijd kiezen"`
- WHEN the overview renders
- THEN the task is a card with the label "EERST DIT" and a "Tijd kiezen" button that opens it

### Requirement: A greeting block opens the overview

A contributed page MAY hold a `greeting` block. It MUST show today's date (unless `showDate` is false) and "Goedemorgen", "Goedemiddag" or "Goedenavond" by the hour, followed by the first name from the session when the session holds a real name. With a `label` and exactly one of `page`, `route` or `action` that resolves, it MUST show that one call to action; a target that does not resolve MUST be dropped and the greeting MUST stay. On `/mijn`, when exactly one home page holds a greeting, the greeting MUST be the screen's heading, carrying the heading's id, and the shell's own greeting MUST NOT also show.

#### Scenario: Monday morning on /mijn
- GIVEN Fatima signed in at 8.30 on Monday 5 October 2026 and a home page that opens with a greeting naming the absence action
- WHEN `/mijn` opens
- THEN it reads "Maandag 5 oktober 2026", "Goedemorgen, Fatima" and offers "Afwezig melden", with one heading at the top
