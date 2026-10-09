# Tasks: mijn lists follow the boards

## 1. portaliq
- [x] 1.1 `ListKeys` (`rowPage`, `rowIdField`, `tabs`); `DisplayKeys` rows and chips keys; PHPUnit `ListKeysTest`.
- [x] 1.2 `lists.js`: day words, "Nieuw", tabs, row route, grouped chips and summary.
- [x] 1.3 `DateRows`, `MarkChips`, `ProgressCards` links and chevrons; `ListTabs`; `ContributionPage` wiring and one heading.
- [x] 1.4 `formatMoment` in words.
- [x] 1.5 Tests: `tests/site-look/mijn-lists-follow-the-boards.spec.mjs`.

## 2. learniq declares (lane LQ)
- [ ] 2.1 vo grades page (StudentPortalPages, `studentGrades`): `['type'=>'collection','collection'=>'studentGrades',
  'label'=>'Grades','display'=>'chips','groupField'=>'courseName','valueField'=>'value','dateField'=>'gradedAt',
  'weightField'=>'weight','subtitleField'=>'<teacher name copy, missing today>','newField'=>'<boolean isNew, missing>',
  'lowBelow'=>5.5,'summary'=>true,'summaryText'=>'Je staat {pass} vakken voldoende en {fail} onvoldoende.',
  'rowPage'=>'<subject detail page, FIX-P>','rowIdField'=>'courseId',
  'tabs'=>[['label'=>'Period 1','field'=>'period','values'=>['1']],['label'=>'Whole school year'],['label'=>'School exam','field'=>'period','values'=>['SE']]]]`.
  Data gaps for LQ: a teacher name on the grade, an "unseen" boolean, CKV and LO as words (`valueLabels`), not "2" and "3".
- [ ] 2.2 vo overview "Laatste cijfers": `'display'=>'rows','titleFields'=>['courseName'],'subtitleField'=>'methodName',
  'valueField'=>'value','dateField'=>'gradedAt','dateDisplay'=>'line','newField'=>'<isNew>','rowStyle'=>'lines'`.
- [ ] 2.3 vo overview homework (`studentHomework`): a collection block `'display'=>'rows','titleFields'=>['title'],
  'eyebrowField'=>'<subject name copy>','dateField'=>'dueAt','dateDisplay'=>'eyebrow','statusField'=>'<state>',
  'statusTones'=>['open'=>'warning','test'=>'neutral'],'rowStyle'=>'lines'` instead of the highlight tasks block.
- [ ] 2.4 training bookings (`employerBookings`, overview and page): `'display'=>'rows','rowPage'=>'<booking page, FIX-P>',
  'dateDisplay'=>'tile','tabs'=>[['label'=>'Upcoming','field'=>'lifecycle','values'=>[...]],['label'=>'Finished',...],['label'=>'Cancelled',...]]`;
  the block `label` equals the page label (one heading); move the status note into `subtitleField`.
- [ ] 2.5 training certificates (overview): `'display'=>'rows','rowStyle'=>'lines','dateField'=>'validUntil',
  'dateDisplay'=>'end','dateLabel'=>'Geldig tot','subtitleField'=>'<holder names>'`.
- [ ] 2.6 po child cards: `'rowPage'=>'<child page>'` so a card opens the child.
- [ ] 2.7 mbo "Eerdere weken" (`studentHourWeeks`): `'display'=>'rows','rowStyle'=>'lines','statusField'=>'lifecycle'` with tones, instead of the table.
