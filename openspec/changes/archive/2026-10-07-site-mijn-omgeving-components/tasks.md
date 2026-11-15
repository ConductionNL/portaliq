# Tasks: site-mijn-omgeving-components

Five waves (design D10). Each wave is one PR to `development`, runs `npm run build:site` within the budget, adds nl, en and en_US strings (`npm run check:l10n-js`), and adds a Playwright test under `tests/e2e/` citing each UI scenario it covers with `@e2e`. Precondition for waves 2 to 5: `site-links-the-theme-bridge` merged and thematiq#892 released.

## Wave 1: live join rows only (REQ-SMO-023)

- [x] **T1**: `via.when` and `via.validUntilField` in `PortalObjectReader::isValidVia()` and `verifiedJoinTargets()`; `when` checked with the `RowWhenNormaliser` grammar (design D9).
  - PHPUnit `PortalObjectReaderTest::testAJoinRowOutsideWhenGrantsNothing`, `::testAnExpiredJoinRowGrantsNothing` (an empty end date grants inside it), `::testAMalformedLiveRowMemberFailsClosed`, `::testASingleReadThroughAWithdrawnJoinRowIsNull`; the rule lives in `ViaJoinRowFilter`
  - Mutation: removing either check fails a test

## Wave 2: rows, badges, empty and loading states (REQ-SMO-001, REQ-SMO-004, REQ-SMO-009; blocks `tasks`, `inbox` of REQ-SMO-021)

- [x] **T2**: exact-pinned `@gemeente-denhaag/action` and `data-badge` CSS; `ActionRow.vue`, `DataBadge.vue`, `EmptyState.vue`, `Skeleton.vue` under `src/site/components/mijn/`; a build test that no chunk holds `react` or a Den Haag JS module (design D1).
- [x] **T3**: `TasksPage.vue` and `MessagesPage.vue` render rows as `ActionRow`; empty and loading states.
  - `check:tasks-page`, `check:site-inbox-pages` stay green
- [x] **T4**: `PortalBlockResolver` accepts `tasks` and `inbox`; the site renders them.
  - PHPUnit `PortalBlockResolverTest::testATasksBlockNamesAContributedCollection`, `::testAnInboxBlockMayNameAnInboxCollection`, `::testAPlaceholderNameIsDropped`
  - Built in wave 2: `ListBlockNormaliser`; node `check:mijn-components`; build check `scripts/check-site-chunks.js` after `build:site`; e2e `tests/e2e/site-mijn-action-rows.spec.ts`. The CSS is imported as `@gemeente-denhaag/<name>/index.css`, the path each package exports for `dist/index.css`.

## Wave 3: case cards and steps (REQ-SMO-002, REQ-SMO-003, REQ-SMO-022, REQ-SMO-030; blocks `cases`, `steps`)

- [x] **T5**: `steps: { label?, provider }` on a `cases` collection, `StepsProviderMethod` beside `TimelineProviderMethod`; `dueField`, `turnField` kept only when projected.
  - PHPUnit `CollectionConfigNormaliserTest::testStepsNeedAProviderMethod`, `::testATurnFieldMustBeProjected`; a provider answer with a bad `state` loses that entry
- [x] **T6**: `_caseTypeName` stamped by `PortalCaseListReader` through `CaseTypeReader` (design D8).
  - PHPUnit `PortalCaseListReaderTest::testEachRowCarriesItsCaseTypeName`, `::testAnUnknownTypeLeavesNoName`
- [x] **T7**: `CaseCard.vue`, `ProcessSteps.vue` on `@gemeente-denhaag/card`, `process-steps`, `step-marker`; blocks `cases` and `steps`; "Mijn zaken" as cards.
  - `check:my-cases-page`, `check:my-cases-acting-for` updated; e2e on dossiq's `mijnZaken`
  - Built in wave 3: `StepsProviderMethod` (normalise, steps shape), `GET .../{id}/steps` on `PortalTimelineController`, `CaseTypeNames`, blocks `cases` and `steps` in `ListBlockNormaliser` (steps only on its record page), `CaseCard`, `ProcessSteps`, `CasesBlock`, `StepsBlock`; node `check:mijn-cases`; e2e `tests/e2e/site-mijn-case-cards.spec.ts` on a seeded portalPage cases collection (dossiq is not in CI). The cases block shows no type name yet: only the Mijn zaken read stamps `_caseTypeName`.
  - Also in wave 3 (REQ-SMO-009, found live): a failed read is a `LoadError` alert with "Opnieuw proberen" on My tasks, the messages page, Mijn zaken and the tasks, inbox, cases and steps blocks, never an empty list.

## Wave 4: documents, timeline, description list (REQ-SMO-005; blocks `documents`, `timeline`)

- [x] **T8**: `FileItem.vue`, `ContactTimeline.vue`, `DescriptionList.vue`; blocks `documents` and `timeline` over the existing providers; `DetailCard.vue` and `CitizenCase.vue` use them.
  - `check:case-timeline`, `check:case-documents-screen` updated
  - Built in wave 4: `FileItem` (@gemeente-denhaag/file 2.5.3), `ContactTimeline` (contact-timeline 4.1.3), `DescriptionList` (Utrecht data list look, own CSS); `DocumentsBlock` reads the case screen's route, `TimelineBlock` the timeline route; `ListBlockNormaliser::recordBlock()` keeps `steps`, `documents` and `timeline` only on their record page. `TimelineList` and `DetailCard` render the timeline and description list, `CitizenCase` the file items. Node `check:mijn-documents`; e2e `tests/e2e/site-mijn-description-list.spec.ts` (the two blocks need an app provider, not in CI).
  - Also in wave 4 (left by wave 3): the collection read of a `cases` collection stamps `_caseTypeName` (`CaseTypeNames::stampRows()` in `ContributionController::collection()`), so a `cases` block names each type.

## Wave 5: pages, menu, home, switching (REQ-SMO-006, REQ-SMO-007, REQ-SMO-008, REQ-SMO-020; `limit`, `sort`, `range` of REQ-SMO-021)

- [x] **T9**: `PortalPageResolver` keeps `group` (after #1097), `menu: false`, `perRecord`, `records`, `home`; `perRecord` dropped off its record collection.
  - [x] server side, built early in `feat/site-design-wave-1`: `PageMenuKeys`, PHPUnit `PageMenuKeysTest::testMenuFalseKeepsTheRoute`, `::testOnlyFalseAndTrueAreKept`, `::testABareRecordsIdIsReadAsACollection`, `::testPerRecordNeedsItsRecordCollection`. `group` stays #1097's. `records.subtitleLookup` (REQ-SMO-026) is not in it yet.
  - [x] the site honouring the keys (menu, switcher, home), built in wave 5: `residentMenuGroups` leaves out `menu: false` and lists `perRecord` pages per row (`loadPerRecordRows`), `navEntryForRoute`/`recordIdOfRoute` read `/mijn/<app>/<page>/<id>`, `MijnHome` at `/mijn`, `RecordSwitcher` on a `records` page; node `check:mijn-home`
- [x] **T10**: `limit` and `sort` on `collection`, `range` on `calendar`.
  - PHPUnit on the normalisers; `check:record-page`, `check:collection-table` extended
  - Built: `CollectionListKeys` (PHPUnit `PortalBlockResolverTest::testACollectionBlockKeepsItsLimitAndSort`, `::testACalendarBlockKeepsItsRange`); on the site `src/shared/listWindow.js` and ContributionPage ("Bekijk alle ..."), node `check:mijn-lists`.
- [x] **T11**: resident menu icons, groups, `menu: false`, `perRecord` entries; Den Haag side navigation look.
  - `check:site-resident-menu`, `check:site-navigation` extended
- [x] **T12**: `RecordSwitcher.vue`, `ActingForBar.vue`, `QuickTiles.vue`, `FigureTiles.vue`; `/mijn` home (design D4) in `accountArea.js`.
  - e2e: the guardian switches child; Linda's bar on a phone width; a portal with nothing to do
  - Partly built in wave 5: `RecordSwitcher.vue` and the `/mijn` home (`MijnHome.vue`, `home.js`; `accountRedirect` no longer redirects `/mijn`). `ActingForBar.vue` and the e2e for the switch and Linda's bar (`tests/e2e/site-mijn-switching.spec.ts`) built in the next round. `QuickTiles.vue` built in wave 6, and a live run on :8090 then showed the tile's route being undone by the record it also kept in storage (fixed: a route that names the record keeps nothing). ~~Still open: `FigureTiles.vue` only, which waits on #1125.~~ Done in `site-school-blocks` wave 2, after #1125 merged: the figure tiles are `KpiCards.vue` restyled (quiet label and details, loud figure, the website's card radius), not a second component over the same data, as design D1's row says ("`KpiCards` data, value and label in one text run").
  - T11 built in wave 5: page icons (MDI names, `src/site/lib/menuIcons.js`, loaded on demand) and the Den Haag side navigation classes and CSS (`@gemeente-denhaag/sidenav` 2.0.0, on demand). Groups were #1097's.
  - Wave 5 also: case cards use Den Haag's default card in a grid (live finding on wave 3: the `--list` appearance and a local reset made them flat rows); e2e `tests/e2e/site-mijn-omgeving-live.spec.ts` seeds and removes its own contribution.

- Register: portalPage 0.4.0 (register 0.56.0) declares every key the resolvers accept, so a contribution authored as a record can use them; the e2e seeds live in `tests/e2e/fixtures/mijn-omgeving-pages.json` and `PortalPageSchemaTest` validates them.

## Wave 6: what the app lanes found after alignment (REQ-SMO-010, REQ-SMO-024 to REQ-SMO-028, `range: day`)

- [x] **T14**: `cta` with `page`, `route`, `withRecord` and a `{title}` label; `richText` `template` with `whenEmpty`.
  - PHPUnit `PortalBlockResolverTest::testACtaNamesExactlyOneTarget`, `::testAnOutsideRouteIsDropped`; node test: a template value is text, not markup
- [x] **T15**: record scope, `lookups` and `excludeWhen` on `tasks`; `recordField` on `inbox`; `range: day`; `display: cards` with `progress`; `subtitleLookup`.
  - PHPUnit on the normalisers; node tests for the excluded row and the progress text
- [x] **T16**: `navKeyFor` matches record pages and opens the record route.
  - Live on :8090: a `cta` with `page` + `withRecord` offered the right address and the page never changed. `openTile` kept the open record in storage as well, and the shell reads a kept record BACK (`followAccountRoute` → `openRecordEntry` → `navKeyFor`), which lands on the page that LISTS the collection — the page the tile is on. A route that names the record now keeps nothing; a target page that shows the collection as a list still keeps it, because there the route cannot name the record. Node: `check:mijn-wave6`; the control fails without the fix.
  - `check:open-record` extended
  - Built in wave 6: `CtaBlockNormaliser` (action, page or route, `withRecord`, `{title}`) and `QuickTiles`; richText `template`/`whenEmpty` (`template.js`); tasks record scope, lookups and `excludeWhen`, inbox `recordField`; `records.subtitleLookup`; `display: cards` with `progress` (`ProgressCards`); `navKeyFor` falls back to a record page and a record link opens on `<page>/<id>`. Node `check:mijn-wave6`; PHPUnit in `PortalBlockResolverTest`, `PageMenuKeysTest`; portalPage 0.5.0 declares the keys; e2e family page in `site-mijn-switching.spec.ts`. Not built: an action cta's `withRecord` preset (actions have no `recordField` key; that is the action normaliser's, lane pq-b).
  - Also in wave 6: the shell learns the mandates when the session loads (`learnMandates(fetchMyCases())` in App `loadAccount`), so `ActingForBar` names its party before Mijn zaken opened. `FigureTiles` still waits on #1125.

## Validation

- [x] **T13**: `openspec validate site-mijn-omgeving-components --strict`
