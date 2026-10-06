# Tasks: calendar-timetable-display

- [x] **T1**: `TimetableKeys` (block `display: timetable`, `firstLabel`; source `noteField`, `statusField`, `cancelledWhen`), called from `CalendarSourceNormaliser` and `PortalBlockResolver`; portalPage 0.7.0 declares `timetable` and `firstLabel`, register 0.67.0.
  - PHPUnit `TimetableKeysTest` (4, through the real `PortalManifestNormaliser`)
- [x] **T2**: `timetableParts()` on every calendar item; `withStatusLabels()` hands a source the words of its status field; `timetable.js` (week tiles, the rows and breaks of a day, the summary, the clock); `TimetableDay.vue` on demand; `ContributionPage` routes `display: timetable`; Dutch and English words in `mijn/strings.js`.
  - node `tests/calendar-timetable.spec.mjs` (`check:calendar-timetable`, in `check:specs`)
- [ ] **T3**: live check next to the Vaartveld `MijnOverzicht` and `MobielDetail` boards, once learniq declares `studentSessions` (learniq `site-pupil-portal-design` T1, T5b).
- [x] **T4**: `openspec validate calendar-timetable-display --strict`
