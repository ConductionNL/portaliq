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

- [ ] **T8**: `FileItem.vue`, `ContactTimeline.vue`, `DescriptionList.vue`; blocks `documents` and `timeline` over the existing providers; `DetailCard.vue` and `CitizenCase.vue` use them.
  - `check:case-timeline`, `check:case-documents-screen` updated

## Wave 5: pages, menu, home, switching (REQ-SMO-006, REQ-SMO-007, REQ-SMO-008, REQ-SMO-020; `limit`, `sort`, `range` of REQ-SMO-021)

- [ ] **T9**: `PortalPageResolver` keeps `group` (after #1097), `menu: false`, `perRecord`, `records`, `home`; `perRecord` dropped off its record collection.
  - [x] server side, built early in `feat/site-design-wave-1`: `PageMenuKeys`, PHPUnit `PageMenuKeysTest::testMenuFalseKeepsTheRoute`, `::testOnlyFalseAndTrueAreKept`, `::testABareRecordsIdIsReadAsACollection`, `::testPerRecordNeedsItsRecordCollection`. `group` stays #1097's. `records.subtitleLookup` (REQ-SMO-026) is not in it yet.
  - [ ] the site honouring the keys (menu, switcher, home) is wave 5
- [ ] **T10**: `limit` and `sort` on `collection`, `range` on `calendar`.
  - PHPUnit on the normalisers; `check:record-page`, `check:collection-table` extended
- [ ] **T11**: resident menu icons, groups, `menu: false`, `perRecord` entries; Den Haag side navigation look.
  - `check:site-resident-menu`, `check:site-navigation` extended
- [ ] **T12**: `RecordSwitcher.vue`, `ActingForBar.vue`, `QuickTiles.vue`, `FigureTiles.vue`; `/mijn` home (design D4) in `accountArea.js`.
  - e2e: the guardian switches child; Linda's bar on a phone width; a portal with nothing to do

## Wave 6: what the app lanes found after alignment (REQ-SMO-010, REQ-SMO-024 to REQ-SMO-028, `range: day`)

- [ ] **T14**: `cta` with `page`, `route`, `withRecord` and a `{title}` label; `richText` `template` with `whenEmpty`.
  - PHPUnit `PortalBlockResolverTest::testACtaNamesExactlyOneTarget`, `::testAnOutsideRouteIsDropped`; node test: a template value is text, not markup
- [ ] **T15**: record scope, `lookups` and `excludeWhen` on `tasks`; `recordField` on `inbox`; `range: day`; `display: cards` with `progress`; `subtitleLookup`.
  - PHPUnit on the normalisers; node tests for the excluded row and the progress text
- [ ] **T16**: `navKeyFor` matches record pages and opens the record route.
  - `check:open-record` extended

## Validation

- [ ] **T13**: `openspec validate site-mijn-omgeving-components --strict`
