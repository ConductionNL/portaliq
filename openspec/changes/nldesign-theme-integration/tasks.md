# Tasks: nldesign-theme-integration

## 1. Adoption — a portal picks a real token set

- [x] 1.1 Read the catalogue rather than probing the filesystem for `css/tokens/<theme>.css`. Resolved server-side from the theme app's `token-sets.json` — the same file `CatalogController` serves — because the renderer runs for ANONYMOUS visitors and the endpoint is deliberately not public (see 1.2).
- [x] 1.2 Establish that the catalogue endpoint is safe for an ANONYMOUS caller, not merely a non-admin one. **It is not, by design.** `CatalogController::tokenSets()` is `#[NoAdminRequired]` and deliberately not `#[PublicPage]`: its docblock states that exposing admin-uploaded custom sets to anonymous traffic "would be a new information-disclosure surface with no consumer need". The route was left alone; the public renderer reads the catalogue from disk, and the authenticated admin UI remains the endpoint's consumer.
- [x] 1.3 `PortalThemeResolver` resolves `portal.theme` against the catalogue and returns null when the id is unknown. A file present on disk but absent from the catalogue — a generated variant, a leftover — is no longer adoptable.
- [ ] 1.4 Surface the resolvable set to the admin UI so `portal.theme` becomes a chosen id rather than free text.
- [x] 1.5 Test: an unknown id renders UNSTYLED and does not fall back. Covered per branch: uncatalogued file, catalogued-but-missing file, and an unreadable catalogue (fails closed).

## 2. Generation — dark variants and fonts

- [x] 2.1 ~~Link `css/tokens/dark/<id>.css`~~ **Withdrawn on measurement, and the withdrawal is the deliverable.** Implemented, rendered and measured twice: the artefact as generated changed **0 of 1,152,000 pixels**; after `nldesign` was fixed (see 2.5) it changed 53% and left **10 of 11 text nodes below 4.5:1**. This site has no token-driven surface layer. Backed out, with the numbers recorded in `templates/site.php` and pinned by a test so re-adding the line is deliberate.
- [ ] 2.2 Respect the instance's dark-mode toggle the same way `CssInjectionService` does; a portal must not invent a second switch. **Blocked on 2.6.**
- [ ] 2.3 Consume `FontService` for the theme's declared fonts instead of `portaliq/css/nlds/nlds-fonts.css`, which currently re-declares faces by hand because the vendored CSS carried root-relative urls.
- [ ] 2.4 Test: with a generated dark variant present, a `prefers-color-scheme: dark` visitor gets it. **Blocked on 2.6.**
- [x] 2.5 Fix the generator in `nldesign` — three defects found by chasing the 0-pixel result, each of which produced output that reads as a successful run (ConductionNL/nldesign#353): `var()` aliases were never darkened (an alias declared on `:root` resolves there, and the dark block scopes to `body`, a descendant — so only 13 of 600 `--utrecht-*` tokens survived); text was classified as surface outside the `--nldesign-*` naming convention; and `GENERATOR_VERSION` was stamped into every header and read by nothing, so an algorithm fix regenerated **0 of 41 sets**.
- [ ] 2.6 Give the site a token-driven surface layer — bands, cards and the page itself. Painting `body` from `--utrecht-document-*` is verified harmless (0 pixels changed in light mode) and insufficient alone: the inner bands stayed white. This is the real prerequisite for dark mode, and it is a change to the site's own CSS, not to the theme app.
- [x] 2.7 **Stop shipping the design system's CSS twice.** **Done — 393.6 KiB to 358.3 KiB.** Nine of the ten `@utrecht/*-css` imports removed from `main.js` (deleting every injected `<style>` on a rendered portal changed exactly ONE computed property — the skip link's `position` — so that one import stays), and the library's dist CSS excluded by a four-line local loader rather than a new dependency. Worth recording: the dist-CSS exclusion alone changed the output by ZERO bytes, a 1.65 MB module left the module graph and the bundle did not move. A module list is not a byte count. Measured on the rendered Conduction portal: 287 KiB of CSS is injected from `portaliq-site.js` by `style-loader` — `.utrecht-heading-1`, `.ac-c-navigation__primary`, `.utrecht-skip-link` and the rest — while `templates/site.php` ALSO links twelve stylesheets carrying the same `ac-*` / `utrecht-*` system. That injected CSS is the bulk of a 393.6 KiB bundle whose budget is 400 KiB (e2e S18, measured on transferred bytes), which leaves 6.4 KiB of headroom for every future feature: adding the contributed-page renderer alone took it to 415.6 KiB and the work had to be backed out. Extract it (`mini-css-extract-plugin`, or drop the imports the linked files already cover) and link one file instead. This is the prerequisite for §6 and for anything else the site renderer grows.

## 3. Contrast and compliance — at adoption time, not after a review

- [ ] 3.1 Call `ContrastController` for the adopted theme and record the verdict against the portal.
- [ ] 3.2 Refuse — or loudly warn on — a theme whose own tokens fail AA for the surfaces a portal actually paints (bands, cards, footer).
- [x] 3.3 Add the portal's own rendered surfaces to the check, walking to the first ancestor that PAINTS a background. Comparing against the nearest NAMED band produced a false failure in this codebase once already, and the "fix" for it made a working form invisible. **Done** as `tests/site-surfaces.spec.mjs` (`npm run check:surfaces`): ten page/width combinations across both demo portals, walking to the first ancestor that actually paints and skipping scrims below 30% alpha. It also COMPOSITES translucent text over that backdrop before measuring — without it `rgba(255,255,255,0.22)` scored 17.85:1 against a navy band, and a deliberate break passed the check.
- [x] 3.4 Test: a deliberately low-contrast token set is rejected/flagged; a compliant one passes. **Done** as the self-test in `tests/site-surfaces.spec.mjs`, which runs on EVERY invocation before any page is judged: it injects a translucent low-contrast colour, a second `h1` and a 3000px element into a real rendered page and demands each be detected, refusing to report a pass otherwise. Proven by blinding the probe to the alpha channel — the defect this file first shipped with — which makes the self-test fail and the run exit non-zero. Injection happens in the browser, never in the token files, so a run that dies halfway leaves the repo clean.

## 4. Sharing — adopt a theme that came from elsewhere

- [ ] 4.1 Consume `NlDesignThemeShareableConfigType` so a theme shared through OpenRegister can be adopted by a portal.
- [ ] 4.2 Route every shared set through `CustomTokenSetValidator` before it can be linked. Shared configuration is input from another instance and must not be able to inject CSS.
- [ ] 4.3 Decide and document what happens when a shared theme is withdrawn while a portal is using it — the portal must not silently lose its styling.
- [ ] 4.4 Test: a shared set with a hostile declaration is refused, and the refusal is visible.

## 6. Contributed pages — routable, once there is room for them

- [x] 6.1 Route `/diensten/{app}/{page}` to the page a contributing app publishes, rendering its `richText` and `action` blocks, and turn the contributions index entries into links. **Done, once 2.7 made room.** Built, backed out on measurement, and re-landed: at 393.6 KiB there were 6.4 KiB of headroom and this needs ~12; with the duplicated CSS gone the bundle is 370.1 KiB with the feature in. `richText` and `action` blocks render, the index entries are links where a page exists and stay text where none does, and the route is covered by `tests/site-surfaces.spec.mjs`.
- [ ] 6.2 Reconcile `anonymous` actions with the endpoint. A manifest may declare `anonymous: true` — La Franken's "Melding indienen — geen account nodig" does — but `ContributionController::action()` answers 401 without a session, for every action. Either the endpoint honours the flag or the manifest stops offering it; today a citizen portal advertises an anonymous route that does not exist.

## 5. Documentation

- [ ] 5.1 Record in `nldesign` that portals are a consumer of the catalogue, the dark variants and the shareable config type — the docs currently describe the Nextcloud UI only.
- [ ] 5.2 Update ADR-086 §6 ("Portaliq ships NO theming mechanism of its own") to state what it now consumes instead.
