# Tasks: site-school-blocks

## Wave 1: the website blocks (plan L2-1)

- [x] **T1**: `newsItem` 0.4.0 (`public`, `portal`, `audienceLabel`), register 0.59.0; `NewsController::create/update` accept them, `update` without `public` keeps the choice.
  - PHPUnit `NewsControllerTest::testCreatePutsAnItemOnOnePortalsWebsiteOnlyWithAPortal`, `::testUpdateWithoutTheWebsiteChoiceKeepsIt`; `PortaliqRegisterConfigTest` names 0.59.0.
- [x] **T2**: `PublicNewsReader`, `ContentNewsController`, routes `/api/content/news` and `/api/content/news/{id}`.
  - PHPUnit `PublicNewsReaderTest` (8: portal, flag, draft, single children, fields, photos, order and limit, intro) and `ContentNewsControllerTest` (5: cacheable for a visitor, limit, 401 on a sign-in only portal, private when signed in, 404).
- [x] **T3**: `nlQuickTasks`, `nlNewsList`, `nlNewsArticle`, `nlEventList`; `DateTile` and `dates.js`, `links.js`; `publicNews.js`.
- [x] **T4**: `nlSignIn` card display; `WidgetGrid` hands it `signInRoutes` and `signedIn`, and hands the news widgets `portal` and `routeParam`.
- [x] **T5**: `nlList` steps display.
- [x] **T6**: registry, metas (the editor's fields), `SITE_COMPOSITIONS` in the coverage record, the token allow-list.
  - node `tests/site-school-blocks.spec.mjs` (`check:site-school-blocks`, in `check:specs`); `check:widget-registry` gains the compositions test.
- [x] **T7**: chrome lane: pass `signInRoutes: this.signInRoutes` in `App.vue`'s `gridContext()`.
  - Done on this branch; node test "T7" in `tests/site-school-blocks.spec.mjs` asserts it.
- [ ] **T8**: live check on a portal with the primary-school example set: each block next to its board. — not run: needs a live instance

## Wave 2: the Mijn omgeving displays (plan L2-2)

- [x] **T10**: server keys: `DisplayKeys` (rows, bars, chips, richer cards, `statusTones`), `SchoolBlockKeys` (tasks highlight, segmented kpi, calendar tiles, greeting), `metaField` on a calendar source; `greeting` in the block registry.
  - PHPUnit `SchoolBlockKeysTest` (7, through the real `PortalManifestNormaliser`).
- [x] **T11**: `DateRows`, `GradeBars`, `MarkChips`, `SegmentedFigure`, `CalendarTiles`, `GreetingBlock`, each on demand; `ProgressCards` gains the status, note, coming-up part and initial; `TasksBlock` the highlight card; `KpiCards` the figure-tile look; `ContributionPage` routes them; `MijnHome` hands its heading to a greeting.
  - node `tests/site-school-displays.spec.mjs` (`check:site-school-displays`, in `check:specs`).
- [x] **T13**: `visibleFromField` on a collection (request of the learniq lane): `VisibleFromGate` in the collection list, the read by id and the inbox; the normaliser projects the field.
  - PHPUnit `VisibleFromGateTest` (3), `PortalInboxReaderTest::testAMessageWaitsForItsVisibleFromMoment`, `ContributionControllerTest::testARowBeforeItsVisibleFromMomentIsNotServed`.
- [ ] **T12**: live check on a portal with the primary-school example set, next to the MijnOverzicht, MijnLijst and Detail boards (the learniq lane declares the pages). — not run: needs a live instance

## Validation

- [x] **T9**: `openspec validate site-school-blocks --strict`
