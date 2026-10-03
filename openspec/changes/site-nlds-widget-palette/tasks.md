# Tasks: site-nlds-widget-palette

Six waves (design D7), one PR each, to `development`. Every PR runs `npm run build:site` within the budget, adds nl, en and en_US strings (`npm run check:l10n-js`), and adds a Playwright test under `tests/e2e/` that places each new widget and checks it with axe, citing the scenarios with `@e2e`. Build on #1097's `widgetLabels.js` when it has merged.

## Wave 1: the palette (REQ-SNW-001, REQ-SNW-002, REQ-SNW-011)

- [ ] **T1**: `src/site/widgets/index.js` with `loaders` and `metas`; `meta.js` shape; `PUBLIC_WIDGETS` spreads the loaders; `pageWidgetCatalogue.js` reads `metas` for label, group, fields and size (design D3).
  - node test: no key in `metas` equals a key of the shared dashboard registry; a key renders publicly only through `PUBLIC_WIDGETS`
  - build test: no module under `src/site/widgets/` in the site entry
- [ ] **T2**: `WidgetPaletteDialog.vue`: groups with headings, search with an announced hit count, Dutch labels for `siteNavigation` and `form` (design D2).
  - `check:page-editor`, `check:site-edit-mode` extended
- [ ] **T3**: drag-in from the palette onto the grid in `PageGridEditor.vue`; Enter and click keep placing at the first free cell.
  - `check:site-grid` extended; e2e drag and keyboard placement
- [ ] **T4**: the coverage record: every one of the 101 components with its placement (design D1), and a node test that counts exactly one placement each (REQ-SNW-010).

## Wave 2: content and layout (REQ-SNW-010)

- [ ] **T5**: `nlHeading`, `nlParagraph`, `nlLink`, `nlLinkList`, `nlList`, `nlQuote`, `nlButtonLink`, `nlActionGroup`, `nlDescriptionList`, `nlImage`, `nlTable`, `nlSeparator`, `nlCodeBlock`, `nlAccordion`, `nlVideo`, `nlYouTube`, each with its CSS package (design D1).
- [ ] **T6**: `MarkdownBlock`'s class map gains `strong`, `em`, `sub`, `sup`, `mark`, `code`, `pre`, `hr`, `img`; the matching CSS packages load with the text widget's chunk.
  - the existing sanitiser e2e (S9) stays green

## Wave 3: feedback

- [ ] **T7**: `nlAlert`, `nlNote`, `nlBanner`, `nlDialog` (dialog, modal, alert), `nlDrawer`, `nlProgressBar`, `nlProgressCircle`, `nlToggletip` (design D5 for the own ones).
  - stylesheet test: own widgets use `var(--utrecht-...)` colours only

## Wave 4: navigation

- [ ] **T8**: `nlLanguageNav`, `nlSignIn`, `nlTaskNav`, `nlTabs`; Utrecht pagination inside paging widgets.

## Wave 5: Mijn omgeving (REQ-SNW-012, REQ-SNW-020)

After `site-mijn-omgeving-components` waves 2 to 5.

- [ ] **T9**: `nlCases`, `nlTasks`, `nlInbox`, `nlTimeline`, `nlFigures`, `nlCalendar`, `nlSteps`, `nlFileList`, `nlRecordSwitcher` as placeable widgets over the shell's signed-in data.
  - e2e: signed out, each shows a sign-in prompt and sends no subject request
- [ ] **T10**: `summary` and `audiences` on actions (`ActionConfigNormaliser`, `AttachedActionResolver`); a public start tiles endpoint; `nlStartTiles` (design D6).
  - PHPUnit `ActionConfigNormaliserTest::testSummaryIsKeptUpTo200Characters`, `::testUnknownAudiencesAreDropped`; a controller test that the endpoint returns label, summary, audiences and route only
  - Route auth gate green on the new public route

## Wave 6: form fields (REQ-SNW-003)

After `site-multi-step-forms` wave 1.

- [ ] **T11**: the `form` widget's field list in the inspector, the Formulieren group adding fields, every field type of design D1 rendered through the shared field layer.

## Validation

- [ ] **T12**: `openspec validate site-nlds-widget-palette --strict`
