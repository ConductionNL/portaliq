## ADDED Requirements

### Requirement: A calendar block may draw a day as a timetable

A `calendar` block MAY declare `display: timetable` and an optional `firstLabel` of at most 60 characters. A calendar source MAY name a `noteField`, a `statusField` and a `cancelledWhen` rule (`{field, in}` with at least one non-empty string). The server MUST drop a key that does not fit and keep the block; `firstLabel` MUST be kept only on a timetable.

The site MUST then draw one day: each item with its start and end time, its number in the day, its title, its meta line and its note, in time order. A gap of five minutes or more between two items MUST show as a break with its length in minutes. An item whose `statusField` value has a word in the collection's value labels MUST show that word as a pill; a value without a word MUST show no pill. An item that matches `cancelledWhen` MUST be struck through and MUST carry the pill "Vervalt" ("Cancelled"), so the change never rests on colour or line style alone. The first item that is not cancelled MUST carry `firstLabel` when one is declared. One line MUST say how many items the day has, how many are changed or cancelled, and when the last item that still takes place ends. With `range: week` the site MUST offer Monday to Friday of the current week as day tiles (a weekend day only when an item falls on it), each a button that says its full date and how many items it holds, and MUST open on today when today is one of the tiles.

#### Scenario: Monday's timetable with two changes
- GIVEN a source with seven items on Monday 5 October, one whose `statusField` reads "Ander lokaal" and one that matches `cancelledWhen`
- WHEN the timetable renders on that day
- THEN it shows seven numbered rows with three breaks, the "Ander lokaal" pill on the changed row, the cancelled row struck through with "Vervalt", and the line "7 lesuren · 2 wijzigingen · uit om 14.20 uur"

#### Scenario: A code without a word draws no pill
- GIVEN an item whose `statusField` holds `teacher-absence` and the collection gives that value no word
- WHEN the timetable renders
- THEN the item shows no pill unless it is cancelled

#### Scenario: The week as day tiles
- GIVEN a timetable with `range: week` on Monday 5 October
- WHEN it renders
- THEN it shows five day tiles, Monday pressed, and choosing Tuesday shows Tuesday's items

#### Scenario: A key that does not fit
- GIVEN a calendar block with `display: agenda`, a `firstLabel` and a source whose `cancelledWhen` lists no value
- WHEN the manifest is normalised
- THEN the block keeps its sources and has no `display`, no `firstLabel` and no `cancelledWhen`
