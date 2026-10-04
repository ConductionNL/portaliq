# Tasks: site-nlds-widget-palette

Six waves (design D7), one PR each, to `development`. Every PR runs `npm run build:site` within the budget, adds nl, en and en_US strings (`npm run check:l10n-js`), and adds a Playwright test under `tests/e2e/` that places each new widget and checks it with axe, citing the scenarios with `@e2e`. Build on #1097's `widgetLabels.js` when it has merged.

## Wave 1: the palette (REQ-SNW-001, REQ-SNW-002, REQ-SNW-011)

- [x] **T1**: `src/site/widgets/` with `loaders.js` (the site's half) and `index.js` (`metas`, the groups and the meta checks); the `meta.js` shape; `PUBLIC_WIDGETS` spreads the loaders through `defineAsyncComponent`; `pageWidgetCatalogue.js` reads `metas` for label, group, fields and size (design D3).
  - **The split is finer than the design drew it, for the reason the design gives.** `loaders` and `metas` cannot live in one module: the renderer imports the loader map eagerly, because that map IS the public gate, so the metas would ride into the site entry with it and every visitor of a public page would download forty widgets' worth of editor text. `loaders.js` holds the imports, `index.js` holds the descriptions and re-exports the loaders so a reader still finds both halves in one place.
  - node test `tests/widget-registry.spec.mjs` (`check:widget-registry`, in `check:specs`): every meta describes itself and both halves exist for every key; no key is a shared dashboard key and every key is `nl`-prefixed; the six groups in REQ-SNW-001's order; and the record below. The meta check is exercised on a deliberately broken meta, so it is a check that can fail.
  - build test in `scripts/check-site-chunks.js`: the entry may hold `loaders.js` and nothing else under `src/site/widgets/`. Controlled by planting a component in the entry map, which fails the script.
  - First widget, so the mechanism is proven rather than described: `nlLink` (design D1 row 53, wave 2's content group), on the already-installed `@utrecht/link-css`, with no new dependency. It refuses an address that is not `http`, `https`, `mailto`, `tel` or a path inside this site, and renders the author's text as plain text instead.
- [x] **T2**: `WidgetPaletteDialog.vue`: groups with headings, search with an announced hit count, Dutch labels for `siteNavigation` and `form` (design D2).
  - **What the search searches**, and why each one is in it: the Dutch label (what the author reads), the synonyms (an author types "zaak", not "case card"), the component's NL Design System name (so somebody working from nldesignsystem.nl finds it by the name on that page), and the key (a developer reading a page's JSON searches for `nlLink`). All four folded to lower case, matched on a substring.
  - The hit count sits in an `aria-live="polite"` region, because an author who types and reads nothing cannot tell a narrow search from a broken one.
  - A group with no match is left out rather than shown empty, and the entries a public page will not mount come last under their own heading, still marked one by one as before. Widgets with no meta yet sit under "Overig", so nothing falls out of the palette while waves 2 to 6 land.
  - Grouping, searching and labelling are plain functions in `src/lib/widgetPalette.js`, so `check:widget-palette` tests them without a browser: the six groups in order, each haystack on its own, case and part words, a word nothing answers to finding nothing, and every placeable entry reading something other than its key.
- [x] **T3**: drag-in from the palette onto the grid in `PageGridEditor.vue`; Enter and click keep placing a widget without a pointer.
  - A palette entry is a `<button>` that is also `draggable`, which is what makes the two gestures one act: the drag carries the key on its own media type (`application/x-portaliq-widget`, so a dropped file is not mistaken for a widget), and a click or Enter places it through the same action the button already had. **How that was tested**: `check:widget-palette` asserts the geometry both gestures produce is identical for the same cell, and that a drop with no usable cell still places something, so the gesture cannot do nothing. The button is the browser's, so Enter and click fire the same handler by construction rather than by a second code path.
  - `cellFromDrop()` in `src/editor/geometry.js` turns a pointer and the canvas rectangle into a cell, and `addWidgetAt()` in `gridModel.js` places there, clamped so the whole widget stays on the grid. Both are arithmetic, both are tested: the middle, the right edge, above and left of the canvas, and an unmeasurable canvas.
  - **A disagreement to settle, not to paper over.** REQ-SNW-002 says a click or Enter places "at the first free cell"; `addWidget()` deliberately appends BELOW everything, and its docblock gives the reason ("an author who adds a widget must be able to find it, and a grid that squeezes it into a gap somewhere in the middle looks like nothing happened"). This change keeps the existing behaviour and does not quietly switch it. On an empty page the two readings agree.
  - Still owed: the Playwright drag and keyboard spec. It needs an editor session on a live instance, and an e2e this lane cannot run is a test that reports nothing; the node tests above cover the arithmetic and the shared action in the meantime.
- [x] **T4**: the coverage record: every one of the 101 components with its placement (design D1), and a node test that counts exactly one placement each (REQ-SNW-010).
  - `src/site/widgets/coverage.js`, beside the registry as the requirement asks, with `coverageByPlacement()` for the count: 48 widget, 17 field, 18 part, 5 inline, 10 shell, 3 none.
  - The test counts it, refuses a component recorded twice or missing, requires a key for a `widget` or `field` and a reason for a `part` or a `none`, and **compares the record against design D1's own table row by row**, so the two cannot drift apart in silence.
  - Two `none` rows had no reason written (Color Sample, Password Input). They have one now, in the design and in the record: a swatch for documenting a palette, and an input a portal never shows because a resident signs in through DigiD, eHerkenning or the broker.

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
