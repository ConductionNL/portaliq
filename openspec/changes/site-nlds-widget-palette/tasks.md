# Tasks: site-nlds-widget-palette

Six waves (design D7), one PR each, to `development`. Every PR runs `npm run build:site` within the budget, adds nl, en and en_US strings (`npm run check:l10n-js`), and adds a Playwright test under `tests/e2e/` that places each new widget and checks it with axe, citing the scenarios with `@e2e`. Build on #1097's `widgetLabels.js` when it has merged.

## Wave 1: the palette (REQ-SNW-001, REQ-SNW-002, REQ-SNW-004, REQ-SNW-011)

- [x] **T1**: `src/site/widgets/` with `loaders.js` (the site's half) and `index.js` (`metas`, the groups and the meta checks); the `meta.js` shape; `PUBLIC_WIDGETS` spreads the loaders through `defineAsyncComponent`; `pageWidgetCatalogue.js` reads `metas` for label, group, fields and size (design D3).
  - **The split is finer than the design drew it, for the reason the design gives.** `loaders` and `metas` cannot live in one module: the renderer imports the loader map eagerly, because that map IS the public gate, so the metas would ride into the site entry with it and every visitor of a public page would download forty widgets' worth of editor text. `loaders.js` holds the imports, `index.js` holds the descriptions and re-exports the loaders so a reader still finds both halves in one place.
  - node test `tests/widget-registry.spec.mjs` (`check:widget-registry`, in `check:specs`): every meta describes itself and both halves exist for every key; no key is a shared dashboard key and every key is `nl`-prefixed; the six groups in REQ-SNW-001's order; and the record below. The meta check is exercised on a deliberately broken meta, so it is a check that can fail.
  - build test in `scripts/check-site-chunks.js`: the entry may hold `loaders.js` and nothing else under `src/site/widgets/`. Controlled by planting a component in the entry map, which fails the script.
  - First widget, so the mechanism is proven rather than described: `nlLink` (design D1 row 53, wave 2's content group), on the already-installed `@utrecht/link-css`, with no new dependency. It refuses an address that is not `http`, `https`, `mailto`, `tel` or a path inside this site, and renders the author's text as plain text instead.
- [x] **T2**: `WidgetPalettePanel.vue` (was `WidgetPaletteDialog.vue`): groups with headings, search with an announced hit count, Dutch labels for `siteNavigation` and `form` (design D2).
  - **What the search searches**, and why each one is in it: the Dutch label (what the author reads), the synonyms (an author types "zaak", not "case card"), the component's NL Design System name (so somebody working from nldesignsystem.nl finds it by the name on that page), and the key (a developer reading a page's JSON searches for `nlLink`). All four folded to lower case, matched on a substring.
  - The hit count sits in an `aria-live="polite"` region, because an author who types and reads nothing cannot tell a narrow search from a broken one.
  - A group with no match is left out rather than shown empty, and the entries a public page will not mount come last under their own heading, still marked one by one as before. Widgets with no meta yet sit under "Overig", so nothing falls out of the palette while waves 2 to 6 land.
  - Grouping, searching and labelling are plain functions in `src/lib/widgetPalette.js`, so `check:widget-palette` tests them without a browser: the six groups in order, each haystack on its own, case and part words, a word nothing answers to finding nothing, and every placeable entry reading something other than its key.
- [x] **T3**: drag-in from the palette onto the grid in `PageGridEditor.vue`; Enter and click keep placing a widget without a pointer.
  - A palette entry is a `<button>` that is also `draggable`, which is what makes the two gestures one act: the drag carries the key on its own media type (`application/x-portaliq-widget`, so a dropped file is not mistaken for a widget), and a click or Enter places it through the same action the button already had. **How that was tested**: `check:widget-palette` asserts the geometry both gestures produce is identical for the same cell, and that a drop with no usable cell still places something, so the gesture cannot do nothing. The button is the browser's, so Enter and click fire the same handler by construction rather than by a second code path.
  - `cellFromDrop()` in `src/editor/geometry.js` turns a pointer and the canvas rectangle into a cell, and `addWidgetAt()` in `gridModel.js` places there, clamped so the whole widget stays on the grid. Both are arithmetic, both are tested: the middle, the right edge, above and left of the canvas, and an unmeasurable canvas.
  - **A disagreement to settle, not to paper over.** REQ-SNW-002 says a click or Enter places "at the first free cell"; `addWidget()` deliberately appends BELOW everything, and its docblock gives the reason ("an author who adds a widget must be able to find it, and a grid that squeezes it into a gap somewhere in the middle looks like nothing happened"). This change keeps the existing behaviour and does not quietly switch it. On an empty page the two readings agree.
  - Still owed: the Playwright drag and keyboard spec. It needs an editor session on a live instance, and an e2e this lane cannot run is a test that reports nothing; the node tests above cover the arithmetic and the shared action in the meantime.
- [x] **T13**: the palette stops covering its own drop target (REQ-SNW-004).
  - **T3 was wired and unreachable, and its node tests could not see that.** The palette was an `aria-modal` NcDialog with a full-screen backdrop, so while it was open the canvas took no pointer at any coordinate: the live run on 4 Oct 2026 caught the dialog's own header intercepting the drop over `designer-canvas`, after 164 retries. `check:widget-palette` passed throughout, because the arithmetic and the shared action were both correct. Ruben decided the panel, not the smaller "close on `dragstart`".
  - `src/editor/WidgetPalettePanel.vue` replaces `src/dialogs/WidgetPaletteDialog.vue`: a labelled, non-modal region in `PageGridEditor.vue`'s pane row, through a `palette` slot, so it sits beside the canvas and before the inspector. Both hosts fill that slot and both openers carry `aria-expanded` and `aria-controls`.
  - What a panel owes the keyboard, since it traps nothing: opening it focuses the search, Escape closes it, and closing returns focus to the opener. Search, grouping, the announced hit count, click and Enter placement are untouched.
  - `PALETTE_DRAG_TYPE` moves to `src/editor/paletteDrag.js`. The canvas used to import it from the palette component, so a rename of the palette would have broken the drop in silence: `getData()` for a type nobody set answers an empty string and the handler returns.
  - The canvas gets a 240px minimum height, because an empty page rendered one line of hint text and there was almost nothing under the pointer to drop onto.
  - **Five new node assertions in `check:widget-palette`**, each mutation-checked: no `NcDialog` and no `aria-modal`; the slot inside the pane row and before the canvas, filled by both hosts; the canvas minimum height; one drag-type module both sides read; Escape, the focus return and the opener's `aria-expanded`.
  - The e2e `a widget can be dragged onto the grid` is no longer `test.fixme`, and it asks `elementFromPoint` whether the drop point is reachable BEFORE it drags, so "something is over the canvas" and "the drop placed nothing" fail as two different sentences rather than as 164 retries and a timeout.
- [x] **T4**: the coverage record: every one of the 101 components with its placement (design D1), and a node test that counts exactly one placement each (REQ-SNW-010).
  - `src/site/widgets/coverage.js`, beside the registry as the requirement asks, with `coverageByPlacement()` for the count: 48 widget, 17 field, 18 part, 5 inline, 10 shell, 3 none.
  - The test counts it, refuses a component recorded twice or missing, requires a key for a `widget` or `field` and a reason for a `part` or a `none`, and **compares the record against design D1's own table row by row**, so the two cannot drift apart in silence.
  - Two `none` rows had no reason written (Color Sample, Password Input). They have one now, in the design and in the record: a swatch for documenting a palette, and an input a portal never shows because a resident signs in through DigiD, eHerkenning or the broker.

## Wave 2: content and layout (REQ-SNW-010)

The entry after this wave: **364,872 bytes** of the 412 KiB budget, up 1,607 bytes from 363,265, which is the sixteen arrow functions in `loaders.js`. Nothing else under `src/site/widgets/` reached it, and `check-site-chunks.js` fails if it ever does.

- [x] **T5**: `nlHeading`, `nlParagraph`, `nlLink`, `nlLinkList`, `nlList`, `nlQuote`, `nlButtonLink`, `nlActionGroup`, `nlDescriptionList`, `nlImage`, `nlTable`, `nlSeparator`, `nlCodeBlock`, `nlAccordion`, `nlVideo`, `nlYouTube`, each with its CSS package (design D1).
  - 15 CSS packages added, exact-pinned. Each widget imports its own, so it arrives in that widget's chunk.
  - **Labels and synonyms are what a Dutch author would type**, not translations of the English component name: "Uitklapbare tekst" for Accordion (synonyms veelgestelde vragen, faq, inklappen), "Opsomming" for Unordered List (lijst, bullets, punten), "Gegevens op een rij" for Description List (kenmerken, in het kort, feiten), "Lijst met links" for Link List (handige links, doorverwijzingen), "Knop" for Button (actie, link als knop), "Groep knoppen" for Action Group (knoppenbalk, keuzes), "Scheidingslijn" for Separator (lijn, streep, witruimte), "Codeblok" for Code Block, "Citaat" for Blockquote (aanhaling, uitspraak), "Afbeelding" for Image (foto, plaatje, beeld).
  - What each widget refuses, rather than renders: an address that is not http, https, mailto, tel or a path inside this site (link, button, link list); a heading level outside 1 to 6; a YouTube value that is not an id, because the embed address is built here and a field taking a whole URL would let an author point the frame anywhere; a table row shorter than its header is padded, since cells sliding under the wrong header is a wrong table, not an untidy one.
  - Accessibility carried in the markup: the accordion's titles are real buttons inside headings with `aria-expanded`; table headers are `th scope="col"`; the YouTube frame has a `title` and uses `youtube-nocookie.com` with `loading="lazy"`; a video is `preload="none"`.
  - `check:widget-registry` reads every widget's source and asserts it imports a design-system stylesheet and renders a design-system class, and that the three without upstream CSS name no colour of their own.
- [x] **T6**: `MarkdownBlock`'s class map gains `strong`, `em`, `sub`, `sup`, `mark`, `code`, `pre`, `hr`, `img`.
  - `li` is deliberately NOT in the map: the list element styles its items, and a class on every `li` would be a second opinion on the same pixels.
  - A class is still added only where one is absent, so authored HTML that already carries design-system classes is left alone. `check:rich-text` stays green, and `check:widget-registry` asserts each new tag is in the map and that `LI` is not.

## Wave 3: feedback

- [x] **T7**: `nlAlert`, `nlNote`, `nlBanner`, `nlDialog` (dialog, modal, alert), `nlDrawer`, `nlProgressBar`, `nlProgressCircle`, `nlToggletip` (design D5 for the own ones).
  - Four packages added for the ones that have upstream CSS (alert-dialog, drawer, note, tooltip); the banner, the dialog's own box, both progress widgets and the toggletip draw themselves from `--utrecht-*` tokens, as D5 says.
  - **`check:widget-tokens` earns its place by catching two things a reading would not.** A literal colour in a widget's stylesheet is invisible in review and on every instance whose theme happens to resemble it: it renders, the colours look plausible, and nothing is wrong until a portal with another set opens the page. The thematiq round of 2 October is that failure from the other side, a site title white on white, found only by a live screenshot. And the case a reviewer waves through: `var(--utrecht-x, #0a3d62)`, which reads as token-driven and renders the hex on exactly the portal the fallback exists for. The test also refuses an own stylesheet on a widget D5 does not list, so a second opinion on upstream pixels cannot arrive quietly. Both halves are run against stylesheets written to break them, and the first version of the test failed on its own prose until it learned to strip comments and to tell `--utrecht-color-grey-30` from `background: grey`.
  - **Keyboard, for a visitor AND for the author in the editor.** The dialog and the drawer are the native `<dialog>`: Escape, the backdrop and the focus containment are the browser's, which is the whole reason not to hand-build an overlay. A `modal` or `alert` dialog opens modally, a plain `dialog` does not, so the page behind it stays usable. Opening moves focus to the close button, which is the way out rather than the first thing to read past; closing returns focus to the button that opened it, by whichever route, including the Escape the browser handles itself (`@close`). **In the editor** the author places the widget and never opens it: the grid renders the opener button, not the open dialog, so there is no state in which the editing surface is behind a modal. If they do open one to check it, Escape and the close button both return them to the opener, and the editor's own save and undo are untouched because the dialog is in the page's own widget, not in the editor chrome.
  - **What announces what, and to whom.** `nlAlert` and `nlBanner` are `role="status"` with `aria-live="polite"` for info and ok, and `role="alert"` with `aria-live="assertive"` for warning and error: something that went wrong should not wait to be reached, and news should not interrupt. `nlNote` announces nothing, on purpose: it is context an author added, not news that arrived. `nlProgressBar` uses the native `progress`, so its value and maximum are read by the browser's own assistive stack, and it adds ONE polite region that says the work is done when it reaches its end, rather than announcing every step. `nlProgressCircle` hides the ring from assistive technology and announces the value as text, so a screen reader reads one number instead of a shape. `nlToggletip` opens on a press (not on hover, so it works on a phone and without a pointer), fills a region that is already in the document so the explanation is read when it arrives, and names its button "Uitleg bij <woord>", because a page with four buttons called "?" is a page with four unnamed buttons.

## Wave 4: navigation

- [x] **T8a**: `nlLanguageNav`, `nlSignIn`, `nlTaskNav`, `nlTabs`. **Reopened 2026-10-05** (Woo capability
  programme, row 6.13): the box was ticked and `nlLanguageNav` cannot render. See T8c. Rendering is fixed on `development` (#1196, `tests/language-nav.spec.mjs`, 7 tests green on this branch); all four widgets exist under `src/site/widgets/`.
  - **Neither nav widget lets a placement invent its own targets.** The language nav renders the locales the shell hands down from the portal, so a page cannot advertise a language the portal does not serve, and it has no author field at all. The sign-in widget renders the ways in the portal declares and **refuses an absolute address**: a sign-in link to another origin is the one link on a government page that must never be authorable.
  - `nlTabs` follows the WAI-ARIA tabs pattern, as `MyCasesPage.vue` already does: a tablist of buttons, arrow keys between them, Home and End to the ends, roving tabindex so Tab leaves the tablist, and one panel at a time wired with `aria-controls` and `aria-labelledby`. The id prefix comes from the first tab's title, so two tab widgets on a page do not point both panels at the same tab.
  - `nlTaskNav` carries each step's state **in words** as well as in weight: a tick and a tint say nothing to a screen reader and nothing to somebody who cannot tell the tints apart, which is WCAG 1.4.1. `aria-current="step"` marks the current one.
  - Tokens only for `nlTabs`, `nlTaskNav` and `nlSignIn`'s layout, with `check:widget-tokens` extended to allow exactly those three and no others.
- [ ] **T8b**: Utrecht pagination inside the paging widgets. — not run: wants its own round with a live look at the public search (see below)
  - **Not done, and not a small rename.** The one paging widget today is `FederatedSearchBlock`, and it draws Amsterdam's `ams-pagination` classes (measured against the reference at page 1 of 36, with the gap marker). Swapping them for `@utrecht/pagination-css` changes how a live public search looks, so it wants its own round with a live check rather than a last-minute edit inside another wave. The package is installed and the scope is one `<nav>` in that file.
  - Design D1 row 69 records this as a `part`, not a widget, so the coverage record and its count are unaffected either way.

## Wave 5: Mijn omgeving (REQ-SNW-012, REQ-SNW-020)

After `site-mijn-omgeving-components` waves 2 to 5.

- [ ] **T9** (not run in this batch: nine widgets over the shell's signed-in data, left for its own pass): `nlCases`, `nlTasks`, `nlInbox`, `nlTimeline`, `nlFigures`, `nlCalendar`, `nlSteps`, `nlFileList`, `nlRecordSwitcher` as placeable widgets over the shell's signed-in data.
  - e2e: signed out, each shows a sign-in prompt and sends no subject request
- [ ] **T10** (not run in this batch: `summary` is already an object on an action (`action-summary-sentence`), so the string form this task asks for needs a decision on the shared key first): `summary` and `audiences` on actions (`ActionConfigNormaliser`, `AttachedActionResolver`); a public start tiles endpoint; `nlStartTiles` (design D6).
  - PHPUnit `ActionConfigNormaliserTest::testSummaryIsKeptUpTo200Characters`, `::testUnknownAudiencesAreDropped`; a controller test that the endpoint returns label, summary, audiences and route only
  - Route auth gate green on the new public route

## Wave 6: form fields (REQ-SNW-003)

After `site-multi-step-forms` wave 1.

- [ ] **T11** (not run in this batch): the `form` widget's field list in the inspector, the Formulieren group adding fields, every field type of design D1 rendered through the shared field layer.

## Validation

- [ ] **T12**: `openspec validate site-nlds-widget-palette --strict` — not run: openspec CLI not installed here

## Amendment, 2026-10-05: Woo capability programme (row 6.13)

Build rules: `openspec/woo-build-rules.md`. A test marked **fails today** must be run
on `origin/development` first and seen red; put the failing line in the PR body.

- [ ] **T8c**: the language switch renders and the chosen language reaches the content (REQ-SNW-013).
  **Start from PR #1196** (`fix/language-switch-renders`): it implements exactly this, with
  `src/site/lib/languageNav.js` and `tests/language-nav.spec.mjs` (7 tests, 5 red on `development`).
  On 2026-10-05 it was CONFLICTING and red on `validate` and `check:specs`. Merge `development` into
  that branch (never rebase it), fix both, and fold its own change `language-switch-reaches-the-content`
  into this one so one spec owns the requirement. If #1196 was closed or merged by the time you start,
  read `git log origin/development -- src/site/lib/languageNav.js` first and build only what is
  missing. Verification:
  - **fails today**: node test `tests/language-nav.spec.mjs` `the grid hands the portal's locales to
    the switch`, `an author cannot add a locale the portal does not serve`, and
    `every content read carries the chosen locale`, wired into `check:specs`.
  - Status: the node tests above pass on this branch because #1196 merged; the e2e and the live check below are not run: need a live instance.
  - e2e `tests/e2e/site-language.spec.ts`: on a portal with locales `nl` and `en`, choose English and
    see a page with an English translation render in English, then follow a link and stay in English.
    Cite REQ-SNW-013.
  - Live check after merge on the dev instance: one portal with two locales; record the switch and
    one translated page.
- [ ] **T8d**: Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint`, `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`,
  `npm run check:manifest`, `npm run check:specs` and `npm run build:site`, plus any other leg
  `code-quality.yml` requires. Hydra's `scripts/run-hydra-gates.sh --base origin/development`, gates
  counted. `TMPDIR` a sibling of the clone. One PR, `--base development`, merge never rebase, no
  `Co-Authored-By`. Done means merged on `development` with CI green; 6.13 then reads `yes` (build),
  and `production` only with a store release. — not run: this is the pre-push list for the PR, which this batch does not open
