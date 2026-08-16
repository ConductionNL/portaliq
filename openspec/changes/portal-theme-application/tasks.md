# Tasks: portal-theme-application

> Make the theme reference change what a visitor sees (ADR-032 `kind: code`).
> Checkbox budget: 3 tasks × 2 = 6 unindented `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Load the resolved site's themiq token set
- **spec_ref**: `openspec/changes/portal-theme-application/specs/portaliq-cms/spec.md#requirement-a-sites-theme-must-change-its-rendered-appearance`
- **files**: `templates/site.php`, `src/site/main.js`, `lib/Controller/PortalPageController.php`
- **acceptance_criteria**:
  - The token stylesheet for the resolved theme is loaded render-blocking, before first paint, so a visitor never sees the wrong branding flash
  - ONLY the active theme's tokens are transferred — phase one moved 527 KiB out of exactly this path
  - The `--pq-*` fallbacks in component styles are removed; where a fallback is unavoidable it is visibly neutral, never a plausible brand colour
  - An unresolvable theme names itself and the page presents as unstyled rather than defaulting to something reasonable
- [ ] Implement
- [ ] Test

### Task 2: Emit NL Design component markup
- **spec_ref**: `openspec/changes/portal-theme-application/specs/portaliq-cms/spec.md#requirement-markup-must-carry-nl-design-component-classes`
- **files**: `src/site/App.vue`, `src/site/components/*.vue`, `appinfo/info.xml`
- **acceptance_criteria**:
  - Heading, links, paragraphs and table carry NL Design component classes; the count is currently ZERO, so any non-zero result is a change in behaviour rather than a restatement
  - No `*-react` design-system package is added (ADR-086 §7; `@utrecht` ships 95 CSS packages to 17 React ones)
  - `appinfo/info.xml` declares the theming dependency, and a missing install is reported
- [ ] Implement
- [ ] Test

### Task 3: The assertion that would have caught this
- **spec_ref**: `openspec/changes/portal-theme-application/specs/portaliq-cms/spec.md#requirement-a-sites-theme-must-change-its-rendered-appearance`
- **files**: `tests/e2e/site-theme.spec.ts`
- **acceptance_criteria**:
  - Renders two differently-themed sites and asserts the SAME element computes DIFFERENT colours — the only assertion that fails today
  - Explicitly does NOT assert on the class name, the token file's presence, or the API's theme string: all three pass right now while both sites render `rgb(26,26,26)`
  - Asserts a theme token resolves to a value that is not the component's hard-coded fallback
  - Run FIRST against the current build and observed FAILING, before the implementation lands — a theming test written after the fix proves only that the fix is present today
- [ ] Implement
- [ ] Test
