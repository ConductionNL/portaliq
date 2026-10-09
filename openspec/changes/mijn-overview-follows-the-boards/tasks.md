# Tasks: mijn overview follows the boards

## 1. portaliq
- [x] 1.1 `BlockLayoutKeys` (`column`, `frame`, `more`) on every block; PHPUnit `BlockLayoutKeysTest`.
- [x] 1.2 `pageLayout.js` places, `BlockShell.vue` draws the grid, frames and links only when declared.
- [x] 1.3 `greeting.showWeek`, `kpi` `display: strip` with `stripLabel`.
- [x] 1.4 Tinted data badges.
- [x] 1.5 Portal schema 0.15.0, register 0.72.0: `contactPrompt`; `PortalShell` projects it; `App.vue` and `ContactPrompt.vue` follow it.
- [x] 1.6 Tests: `tests/site-look/mijn-overview-follows-the-boards.spec.mjs`, `PortalShellTest`.

## 2. learniq declares (lane LQ). Labels in English go through learniq's own Dutch labels.
- [ ] 2.1 vo `studentOverview` (StudentPortalPages::overviewPage): `['type'=>'greeting','showWeek'=>true]`;
  timetable block `+ ['column'=>'main','frame'=>'line','more'=>['label'=>'Whole week','page'=>'studentSessions']]`
  and drop the separate `cta` "Whole week"; homework `tasks` block `+ ['column'=>'side','frame'=>'line',
  'more'=>['label'=>'Everything this week','page'=>'studentHomework','placement'=>'end']]`; grades block
  `+ ['column'=>'side','frame'=>'line','more'=>['label'=>'All grades','page'=>'studentGrades','placement'=>'end']]`;
  absence kpi `+ ['display'=>'strip','frame'=>'tinted','more'=>['label'=>'View','page'=>'studentExcuseRequests']]`
  with `stripLabel` "ziek", "te laat", "zonder melding" (unit "uur" for the last, as the board); drop the
  `cta` "Hand in work", "Report an absence" and the `inbox` block (not on the board).
- [ ] 2.2 po guardian overview (ParentSitePages): news block `+ ['column'=>'main','more'=>['label'=>'All news','route'=>'/nieuws']]`,
  "Deze maand" calendar `+ ['column'=>'side']`; child cards block `label` "Mijn kinderen".
- [ ] 2.3 training employer overview (EmployerSitePages): `greeting` `showDate: false`; the tasks block drops
  `label` "First this" (keeps `eyebrow`); bookings block `+ ['more'=>['label'=>'All bookings','page'=>'employerBookings']]`,
  certificates block `+ ['more'=>['label'=>'All certificates','page'=>'<certificates page>']]`; drop the
  greeting's "Medewerkers inschrijven" action (not on the board).
- [ ] 2.4 mbo student overview: same keys where the board draws columns (Deze week left, Binnenkort right).
- [ ] 2.5 Each portal record: `"contactPrompt": {"show": false}` (no board shows the prompt), or texts in the
  portal's tone.
