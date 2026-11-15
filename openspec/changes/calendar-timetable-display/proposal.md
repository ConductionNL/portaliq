# Proposal: calendar-timetable-display

## Why

The Vaartveld College boards (school-design, 5 October 2026; plan `PORTAL-PLAN.md` item W2-1) draw a pupil's day twice: "Je rooster vandaag" on the overview (seven numbered lessons, the breaks between them, "Ander lokaal" and "Vervalt" pills, a struck-through cancelled hour) and "Rooster van vandaag" on a phone (the days of the week as tiles above a time column). A `calendar` block today draws a month list, a month grid or date tiles. None of them shows times, breaks or a change.

The plan also asked portaliq for `via.when` and `range: day`. Both already exist: `via.when` and `via.validUntilField` landed with `site-mijn-omgeving-components` T1 (REQ-SMO-023, `ViaJoinRowFilter`), and `range: day | week | month` with T10 and T15. This change adds only the drawing.

## What changes

- **`display: timetable`** on a `calendar` block, with an optional `firstLabel` (authored text over the first lesson that still takes place, "Je eerste les").
- **Three source keys**: `noteField` (a third line), `statusField` (its word comes from the collection's `fieldConfigs.<field>.valueLabels`; a value without a word draws no pill) and `cancelledWhen` (`{field, in}`).
- **The drawing** (`TimetableDay.vue`, loaded on demand): one day, each item with its start and end time, a number, title, meta line and note; a break row for a gap of five minutes or more; a pill in words for a change, "Vervalt" for a cancelled item, which is struck through; one line that sums the day up ("7 lesuren · 2 wijzigingen · uit om 14.20 uur"). With `range: week` the days Monday to Friday sit above it as tiles (a weekend day only when something falls on it); the block opens on today.
- **portalPage 0.7.0** (register 0.67.0) declares `timetable` and `firstLabel`, so a portal-authored page may use them.

Generic: no school names, colours or data in portaliq. The words "lesuur" and "lesuren" are the timetable display's own; a portal that is not a school does not choose this display.

## Not in this change

- Linking a lesson to homework or a test ("SO woordjes", "Leesverslag inleveren" on the board): no source key relates two collections. The app may project a note.
- The teacher's name: the learniq `session` has none (see learniq `site-pupil-portal-design`, "Not in this change").
- "Rooster in je agenda zetten" (a calendar feed).
- A server-side date filter: the timetable reads the same rows as every calendar source (at most 200 per collection read) and narrows them in the browser.

## Impact

- `lib/Contribution/TimetableKeys.php` (new), `CalendarSourceNormaliser.php`, `PortalBlockResolver.php`, `lib/Settings/portaliq_register.json` (portalPage only).
- `src/shared/recordPage.js` (`timetableParts`), `src/site/pages/collections/pageBlocks.js` (`withStatusLabels`), `ContributionPage.vue`, `src/site/components/mijn/{TimetableDay.vue,timetable.js,strings.js,index.js}`.
- Tests: PHPUnit `TimetableKeysTest`, node `tests/calendar-timetable.spec.mjs` (`check:calendar-timetable`, in `check:specs`).
