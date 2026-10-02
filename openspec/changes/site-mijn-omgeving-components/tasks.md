# Tasks: site-mijn-omgeving-components

Five waves (design D10). Each wave is one PR to `development`, runs `npm run build:site` within the budget, adds nl, en and en_US strings (`npm run check:l10n-js`), and adds a Playwright test under `tests/e2e/` citing each UI scenario it covers with `@e2e`. Precondition for waves 2 to 5: `site-links-the-theme-bridge` merged and thematiq#892 released.

## Wave 1: live join rows only (REQ-SMO-023)

- [ ] **T1**: `via.when` and `via.validUntilField` in `PortalObjectReader::isValidVia()` and `verifiedJoinTargets()`; `when` checked with the `RowWhenNormaliser` grammar (design D9).
  - PHPUnit `PortalObjectReaderTest::testAJoinRowOutsideWhenGrantsNothing`, `::testAnExpiredJoinRowGrantsNothing`, `::testAnEmptyValidUntilGrants`, `::testAMalformedWhenFailsClosed`
  - Mutation: removing either check fails a test

## Wave 2: rows, badges, empty and loading states (REQ-SMO-001, REQ-SMO-004, REQ-SMO-009; blocks `tasks`, `inbox` of REQ-SMO-021)

- [ ] **T2**: exact-pinned `@gemeente-denhaag/action` and `data-badge` CSS; `ActionRow.vue`, `DataBadge.vue`, `EmptyState.vue`, `Skeleton.vue` under `src/site/components/mijn/`; a build test that no chunk holds `react` or a Den Haag JS module (design D1).
- [ ] **T3**: `TasksPage.vue` and `MessagesPage.vue` render rows as `ActionRow`; empty and loading states.
  - `check:tasks-page`, `check:site-inbox-pages` stay green
- [ ] **T4**: `PortalBlockResolver` accepts `tasks` and `inbox`; the site renders them.
  - PHPUnit `PortalBlockResolverTest::testATasksBlockNamesAContributedCollection`, `::testAnInboxBlockMayNameAnInboxCollection`, `::testAPlaceholderNameIsDropped`

## Wave 3: case cards and steps (REQ-SMO-002, REQ-SMO-003, REQ-SMO-022, REQ-SMO-030; blocks `cases`, `steps`)

- [ ] **T5**: `steps: { label?, provider }` on a `cases` collection, `StepsProviderMethod` beside `TimelineProviderMethod`; `dueField`, `turnField` kept only when projected.
  - PHPUnit `CollectionConfigNormaliserTest::testStepsNeedAProviderMethod`, `::testATurnFieldMustBeProjected`; a provider answer with a bad `state` loses that entry
- [ ] **T6**: `_caseTypeName` stamped by `PortalCaseListReader` through `CaseTypeReader` (design D8).
  - PHPUnit `PortalCaseListReaderTest::testEachRowCarriesItsCaseTypeName`, `::testAnUnknownTypeLeavesNoName`
- [ ] **T7**: `CaseCard.vue`, `ProcessSteps.vue` on `@gemeente-denhaag/card`, `process-steps`, `step-marker`; blocks `cases` and `steps`; "Mijn zaken" as cards.
  - `check:my-cases-page`, `check:my-cases-acting-for` updated; e2e on dossiq's `mijnZaken`

## Wave 4: documents, timeline, description list (REQ-SMO-005; blocks `documents`, `timeline`)

- [ ] **T8**: `FileItem.vue`, `ContactTimeline.vue`, `DescriptionList.vue`; blocks `documents` and `timeline` over the existing providers; `DetailCard.vue` and `CitizenCase.vue` use them.
  - `check:case-timeline`, `check:case-documents-screen` updated

## Wave 5: pages, menu, home, switching (REQ-SMO-006, REQ-SMO-007, REQ-SMO-008, REQ-SMO-020; `limit`, `sort`, `range` of REQ-SMO-021)

- [ ] **T9**: `PortalPageResolver` keeps `group` (after #1097), `menu: false`, `perRecord`, `records`, `home`; `perRecord` dropped off its record collection.
  - PHPUnit `PortalPageResolverTest::testMenuFalseKeepsTheRoute`, `::testPerRecordNeedsItsRecordCollection`, `::testABareRecordsIdIsReadAsACollection`
- [ ] **T10**: `limit` and `sort` on `collection`, `range` on `calendar`.
  - PHPUnit on the normalisers; `check:record-page`, `check:collection-table` extended
- [ ] **T11**: resident menu icons, groups, `menu: false`, `perRecord` entries; Den Haag side navigation look.
  - `check:site-resident-menu`, `check:site-navigation` extended
- [ ] **T12**: `RecordSwitcher.vue`, `ActingForBar.vue`, `QuickTiles.vue`, `FigureTiles.vue`; `/mijn` home (design D4) in `accountArea.js`.
  - e2e: the guardian switches child; Linda's bar on a phone width; a portal with nothing to do

## Validation

- [ ] **T13**: `openspec validate site-mijn-omgeving-components --strict`
