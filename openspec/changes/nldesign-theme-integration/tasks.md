# Tasks: nldesign-theme-integration

## 1. Adoption — a portal picks a real token set

- [ ] 1.1 Read the catalogue via `nldesign`'s `CatalogController` (`GET /apps/nldesign/api/token-sets`) rather than probing the filesystem for `css/tokens/<theme>.css`.
- [ ] 1.2 Establish that the catalogue endpoint is safe for an ANONYMOUS caller, not merely for a non-admin one. If it is not, add an anonymous-safe projection rather than widening the existing route.
- [ ] 1.3 `PortalThemeResolver` resolves `portal.theme` against the catalogue and returns null when the id is unknown — never a default, never another portal's theme.
- [ ] 1.4 Surface the resolvable set to the admin UI so `portal.theme` becomes a chosen id rather than free text.
- [ ] 1.5 Test: an unknown id renders UNSTYLED and says so in a log line; it must not fall back.

## 2. Generation — dark variants and fonts

- [ ] 2.1 Link `css/tokens/dark/<id>.css` when `nldesign` has generated one, after the light layer so its media-query rules win.
- [ ] 2.2 Respect the instance's dark-mode toggle the same way `CssInjectionService` does; a portal must not invent a second switch.
- [ ] 2.3 Consume `FontService` for the theme's declared fonts instead of `portaliq/css/nlds/nlds-fonts.css`, which currently re-declares faces by hand because the vendored CSS carried root-relative urls.
- [ ] 2.4 Test: with a generated dark variant present, a `prefers-color-scheme: dark` visitor gets it; with none, nothing changes.

## 3. Contrast and compliance — at adoption time, not after a review

- [ ] 3.1 Call `ContrastController` for the adopted theme and record the verdict against the portal.
- [ ] 3.2 Refuse — or loudly warn on — a theme whose own tokens fail AA for the surfaces a portal actually paints (bands, cards, footer).
- [ ] 3.3 Add the portal's own rendered surfaces to the check, walking to the first ancestor that PAINTS a background. Comparing against the nearest NAMED band produced a false failure in this codebase once already, and the "fix" for it made a working form invisible.
- [ ] 3.4 Test: a deliberately low-contrast token set is rejected/flagged; a compliant one passes.

## 4. Sharing — adopt a theme that came from elsewhere

- [ ] 4.1 Consume `NlDesignThemeShareableConfigType` so a theme shared through OpenRegister can be adopted by a portal.
- [ ] 4.2 Route every shared set through `CustomTokenSetValidator` before it can be linked. Shared configuration is input from another instance and must not be able to inject CSS.
- [ ] 4.3 Decide and document what happens when a shared theme is withdrawn while a portal is using it — the portal must not silently lose its styling.
- [ ] 4.4 Test: a shared set with a hostile declaration is refused, and the refusal is visible.

## 5. Documentation

- [ ] 5.1 Record in `nldesign` that portals are a consumer of the catalogue, the dark variants and the shareable config type — the docs currently describe the Nextcloud UI only.
- [ ] 5.2 Update ADR-086 §6 ("Portaliq ships NO theming mechanism of its own") to state what it now consumes instead.
