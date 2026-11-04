# Tasks: guardian-pages-follow-the-wilgenboom-boards

- [x] **T1** `residentMenu.js`: per-record items carry the page's `app:page` name; a layout name places every item of that name. Test: `tests/resident-menu-badges.spec.mjs` "a portal layout places a per-record page with all its rows".
- [x] **T2** cta labels: `recordLabel()` in `template.js` fills any `{field}` of the open record; `CtaBlockNormaliser` keeps `labelWhenEmpty`. Tests: `tests/mijn-wave6.spec.mjs`, `PortalBlockResolverTest::testACtaKeepsTheLabelForAnEmptyField`.
- [x] **T3** rows display: `statusNoteAtField` in `DisplayKeys` and `dateRows()`; `decidedInWords()` draws the line. Tests: `tests/site-school-displays.spec.mjs`, `SchoolBlockKeysTest::testRowsKeepTheMomentTheStatusWasSet`.
- [x] **T4** choice cards: `descriptionField` on a collection provider (`ActionOptionsNormaliser`, `optionOf()`), a second line on `ChoiceCards`; `namedDays()` returns a name and a date. Tests: `tests/site-form-widgets.spec.mjs`, `ActionConfigNormaliserTest::testACollectionProviderKeepsItsDescriptionField`.
- [x] **T5** register 0.105.0, portalPage 0.9.0: `statusNoteAtField`, `labelWhenEmpty`.
- [ ] **T6** learniq (its own repo): declare the menu layout in `po.json`, the child-page ctas, `statusNoteAtField` on the absence rows and `descriptionField: groupLabel` on the child provider.
