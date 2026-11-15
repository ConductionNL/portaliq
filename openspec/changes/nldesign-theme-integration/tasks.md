# Tasks: nldesign-theme-integration

## 1. Adoption — a portal picks a real token set

- [x] 1.1 Read the catalogue rather than probing the filesystem for `css/tokens/<theme>.css`. Resolved server-side from the theme app's `token-sets.json` — the same file `CatalogController` serves — because the renderer runs for ANONYMOUS visitors and the endpoint is deliberately not public (see 1.2).
- [x] 1.2 Establish that the catalogue endpoint is safe for an ANONYMOUS caller, not merely a non-admin one. **It is not, by design.** `CatalogController::tokenSets()` is `#[NoAdminRequired]` and deliberately not `#[PublicPage]`: its docblock states that exposing admin-uploaded custom sets to anonymous traffic "would be a new information-disclosure surface with no consumer need". The route was left alone; the public renderer reads the catalogue from disk, and the authenticated admin UI remains the endpoint's consumer.
- [x] 1.3 `PortalThemeResolver` resolves `portal.theme` against the catalogue and returns null when the id is unknown. A file present on disk but absent from the catalogue — a generated variant, a leftover — is no longer adoptable.
- [x] 1.4 Surface the resolvable set to the admin UI so `portal.theme` becomes a chosen id rather than free text. Built 2026-09-29 as the House style widget on the portal page (`src/widgets/PortalTheme.vue` over `src/lib/portalThemeChoice.js`), served by `PortalThemeController` GET/PUT `/api/portals/{slug}/theme` and `lib/Service/Theme/PortalThemeChoice.php`; only sets `PortalThemeResolver::stylesheetFor()` renders are offered, from the new `catalogue()`.
- [x] 1.5 Test: an unknown id renders UNSTYLED and does not fall back. Covered per branch: uncatalogued file, catalogued-but-missing file, and an unreadable catalogue (fails closed).

## 2. Generation — dark variants and fonts

- [x] 2.1 ~~Link `css/tokens/dark/<id>.css`~~ **Withdrawn on measurement, and the withdrawal is the deliverable.** Implemented, rendered and measured twice: the artefact as generated changed **0 of 1,152,000 pixels**; after `nldesign` was fixed (see 2.5) it changed 53% and left **10 of 11 text nodes below 4.5:1**. This site has no token-driven surface layer. Backed out, with the numbers recorded in `templates/site.php` and pinned by a test so re-adding the line is deliberate.
- [ ] 2.2 Respect the instance's dark-mode toggle the same way `CssInjectionService` does; a portal must not invent a second switch. **Blocked on 2.6.** — not run: waits on the token-driven surface layer
- [x] 2.3 Consume `FontService` for the theme's declared fonts instead of `portaliq/css/nlds/nlds-fonts.css`, which currently re-declares faces by hand because the vendored CSS carried root-relative urls. Built 2026-09-29: `templates/site.php` links the theme app's public `font#css` route (`PortalThemeResolver::fontStylesheetRoute()`, only when the installed build has `FontController`). It does NOT replace `nlds-fonts.css`: that file serves the vendored design system's own faces, a different set (see the reference commit 19fbcd6).
- [ ] 2.4 Test: with a generated dark variant present, a `prefers-color-scheme: dark` visitor gets it. **Blocked on 2.6.** — not run: waits on the token-driven surface layer
- [x] 2.5 Fix the generator in `nldesign` — three defects found by chasing the 0-pixel result, each of which produced output that reads as a successful run (ConductionNL/nldesign#353): `var()` aliases were never darkened (an alias declared on `:root` resolves there, and the dark block scopes to `body`, a descendant — so only 13 of 600 `--utrecht-*` tokens survived); text was classified as surface outside the `--nldesign-*` naming convention; and `GENERATOR_VERSION` was stamped into every header and read by nothing, so an algorithm fix regenerated **0 of 41 sets**.
- [ ] 2.6 Give the site a token-driven surface layer — bands, cards and the page itself. Painting `body` from `--utrecht-document-*` is verified harmless (0 pixels changed in light mode) and insufficient alone: the inner bands stayed white. This is the real prerequisite for dark mode, and it is a change to the site's own CSS, not to the theme app. Tracked as task 2 of `portal-theme-blocks-and-contributed-pages` (`css/site-theme.css` exists and is now held token-only by stylelint); the dark-mode measurement is not run: needs a browser.

## 3. Contrast and compliance — at adoption time, not after a review

- [x] 3.1 Call `ContrastController` for the adopted theme and record the verdict against the portal. Built as `lib/Service/Theme/PortalThemeContrast.php`, which calls thematiq's `ContrastService` in process (the controller needs a session) over `PortalThemeResolver::tokenValuesFor()`; the verdict is shown per set and returned with every save rather than stored.
- [x] 3.2 Refuse — or loudly warn on — a theme whose own tokens fail AA for the surfaces a portal actually paints (bands, cards, footer). The save is refused with the failing tokens until the administrator confirms ("Use it anyway"). Surfaces judged: page and footer, the two painted from a token; bands and cards have no token of their own until task 2.6.
- [ ] 3.3 Add the portal's own rendered surfaces to the check, walking to the first ancestor that PAINTS a background. Comparing against the nearest NAMED band produced a false failure in this codebase once already, and the "fix" for it made a working form invisible. — not run: needs a browser (the rendered-page check)
- [x] 3.4 Test: a deliberately low-contrast token set is rejected/flagged; a compliant one passes. `tests/Unit/Service/Theme/PortalThemeContrastTest.php` and `PortalThemeChoiceTest.php` over thematiq's real `ContrastService` (verbatim copy in `tests/Stubs/Thematiq`), `tests/portal-theme-choice.spec.mjs`.

## 4. Sharing — adopt a theme that came from elsewhere

- [x] 4.1 Consume `NlDesignThemeShareableConfigType` so a theme shared through OpenRegister can be adopted by a portal. Built 2026-09-29 through the theme app rather than beside it: its shareable config type imports a shared theme as a custom set, and `lib/Service/Theme/PortalCustomThemeSets.php` adds the theme app's custom sets (`CustomTokenSetService::list()`, uploads and imports alike) to `PortalThemeResolver::catalogue()`, so the House style widget offers them. Design changed from the reference (copying a bundle into the portal): the theme app stays the one place a set is made, edited and shared.
- [x] 4.2 Route every shared set through `CustomTokenSetValidator` before it can be linked. Shared configuration is input from another instance and must not be able to inject CSS. `PortalThemeResolver::stylesheetFor()` refuses a custom set whose file fails the theme app's validator (`hasDisallowedSelector` + `validateDeclarations` over every declaration, the `;` inside a `url()` kept); without the validator nothing custom is linked.
- [x] 4.3 Decide and document what happens when a shared theme is withdrawn while a portal is using it — the portal must not silently lose its styling. Decided: the portal shows without a house style and its widget says the theme app no longer offers the set (`currentResolves` false); documented in `docs/operations/choosing-a-portal-house-style.md`.
- [x] 4.4 Test: a shared set with a hostile declaration is refused, and the refusal is visible. `PortalCustomThemeSetsTest` (six hostile shapes, the real validator), `PortalThemeResolverTest::testACustomSetResolvesOnlyWhenTheValidatorPassesItsFile`, `PortalThemeChoiceTest::testCustomSetsAreOfferedAndAHostileOneIsRefusedVisibly` (listed with the reason, not choosable, not even confirmed), `tests/portal-theme-choice.spec.mjs`.

## 5. Documentation

- [ ] 5.1 Record in `nldesign` that portals are a consumer of the catalogue, the dark variants and the shareable config type — the docs currently describe the Nextcloud UI only. — not run: needs the theme app repo (the reference note says thematiq#357 merged it)
- [ ] 5.2 Update ADR-086 §6 ("Portaliq ships NO theming mechanism of its own") to state what it now consumes instead. — not run: ADR-086 has no file here; the statement goes in `openspec/specs/portaliq-cms/spec.md` when this change is archived, and archiving is not part of this batch

## Reference implementation, not merged

Branch `feat/portal-nextcloud-signin` built most of the open tasks above. It
never merged and conflicts with `development` in 22 files, so rebuild from
`development` and read these commits for the decisions. Recorded 2026-09-14 by
`portal-theme-blocks-and-contributed-pages`, which owns the site's token layer.

- 1.4 theme choice with verdicts: 09e6ffe (`ThemeController`, `AdminRoot.vue`)
- 2.2 and 2.4 dark variant: linked and withdrawn again in ae48b96. Measured on `conduction-klant`: 0 of 8 surfaces changed and 19 of 38 text nodes fell below AA, worst 1.03:1. The generated dark files redefined base colours on `body` while aliases resolved at `:root`. thematiq#353 has since merged; regenerate the sets and measure again before linking.
- 2.3 uploaded fonts: 19fbcd6 links thematiq's public font stylesheet and keeps `nlds-fonts.css`, which serves a different set of faces.
- 2.6 token-driven surfaces: 03fdd5f. Built into `portal-theme-blocks-and-contributed-pages` task 2.
- 3.1 to 3.4 contrast: 09e6ffe (`PortalThemeContrast`), and the rendered-page check in `tests/site-surfaces.spec.mjs` (a485fad, 9901229).
- 4.1 to 4.4 shared sets: 19fbcd6 (`PortalSharedTheme`).
- 5.1 docs in the theme app: thematiq#357, merged.
- 5.2: ADR-086 has no file anywhere, so the statement belongs in `openspec/specs/portaliq-cms/spec.md` when this change is archived (19fbcd6).
