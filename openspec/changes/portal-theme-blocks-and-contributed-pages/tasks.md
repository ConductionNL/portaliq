# Tasks: portal-theme-blocks-and-contributed-pages

<!-- HYDRA CAP: 10 tasks x 2 checkboxes = 20 unindented checkboxes, the cap.
     The verification and compliance lists below are plain bullets on purpose. -->

Build from `origin/development`. Use the branch commits named in `design.md`
as the reference for each decision, and do not cherry-pick them.

Before task 1, capture the baseline on `development`. Copy
`tests/shell-snapshot.mjs` from the branch (7974e5a), run
`node tests/shell-snapshot.mjs capture <dir>` with `<dir>` outside the repo,
and commit the script, not the capture. Leave out its
`/diensten/portaliq/meldingen` page until task 9 adds that route. Every task
that touches the shell ends with `node tests/shell-snapshot.mjs compare <dir>`.

## Implementation tasks

### Task 1: Stop shipping the design system's CSS twice

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/design.md#d6-contributed-pages-route-from-data-already-loaded`
- **files**: `src/site/main.js`, `webpack.site.js`, `build/empty-css-loader.js`
- **acceptance_criteria**:
  - GIVEN the site bundle WHEN it is built THEN only `@utrecht/skip-link-css` is imported from `@utrecht/*-css`, and `@conduction/nextcloud-vue` dist CSS is excluded while Vue SFC styles stay
  - GIVEN both demo portals WHEN computed styles of header, cards, footer and headings are compared with the baseline THEN nothing differs
  - GIVEN `npm run build:site` WHEN it finishes THEN the entrypoint is reported and stays under 400 KiB
- Reference: 076bd6e
- [ ] Implement
- [ ] Test

### Task 2: Link the theme layers and add a token-only site stylesheet

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-every-surface-the-site-paints-must-read-a-theme-token-req-ptb-001`
- **files**: `templates/site.php`, `css/site-theme.css`, `src/site/App.vue`, `.stylelintrc*`
- **acceptance_criteria**:
  - GIVEN a portal on a resolvable theme WHEN the site renders THEN the link order is bridge, set chain, vendored sheets, `site-theme.css`
  - GIVEN a rendered portal WHEN only `--utrecht-document-background-color` changes THEN all painted surfaces change, including `.pq-site`'s scoped style
  - GIVEN `css/site-theme.css` WHEN stylelint runs THEN `color-no-hex` and `color-named: never` pass
- Reference: 03fdd5f, 93a0ecd, 46d7e9f, fd37778
- [ ] Implement
- [ ] Test

### Task 3: Portal token overrides and theme inheritance

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002`, `#requirement-a-theme-that-extends-another-must-load-its-parent-first-req-ptb-003`
- **files**: `lib/Service/PortalTokenCss.php`, `lib/Service/PortalThemeResolver.php`, `lib/Controller/PortalPageController.php`, `lib/Settings/portaliq_register.json`, `tests/Unit/Service/PortalTokenCssTest.php`, `tests/Unit/Service/PortalThemeResolverTest.php`
- **acceptance_criteria**:
  - GIVEN the hostile values from REQ-PTB-002 WHEN rendered THEN each is dropped and the valid ones remain
  - GIVEN a child set, its parent and a two-set cycle WHEN the chain resolves THEN the parent is first and the cycle yields each set once
  - GIVEN `portal.tokens` WHEN the register is imported THEN the schema declares it with a description
- Reference: c06adac, 46d7e9f
- [ ] Implement
- [ ] Test

### Task 4: The header block

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004`, `#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007`
- **files**: `src/site/components/BrandHeader.vue`, `src/site/lib/blockProps.js`, `src/site/lib/shellData.js`, `src/site/App.vue`, `lib/Controller/ContentController.php`, `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN a portal with no new fields WHEN compared with the baseline THEN the header is unchanged
  - GIVEN `headerVariant: "single"` WHEN rendered THEN one "Home" link exists; GIVEN `"x"` THEN `double` renders
  - GIVEN #559's sign-in states on `development` WHEN the header moves into the block THEN every state still renders
- Reference: 7974e5a, 045b168, fd37778, a485fad
- [ ] Implement
- [ ] Test

### Task 5: The footer block

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005`
- **files**: `src/site/components/FooterColumns.vue`, `css/site-theme.css`, `lib/Controller/ContentController.php`, `lib/Settings/portaliq_register.json`, `tests/Unit/Controller/ContentControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a third band appended in the browser WHEN the first two are measured THEN nothing changed
  - GIVEN footer entries without a label or href WHEN served THEN they are absent
  - GIVEN no colophon WHEN rendered THEN the legal bar shows the portal title
- Reference: 34cde3e, 423d7df, fd37778
- [ ] Implement
- [ ] Test

### Task 6: The hero block and grid runs

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006`
- **files**: `src/site/components/HeroBlock.vue`, `src/site/components/WidgetGrid.vue`, `src/site/lib/gridPlacement.js`, `tests/site-grid.spec.mjs`, `css/site-theme.css`
- **acceptance_criteria**:
  - GIVEN four actions WHEN rendered THEN two links appear
  - GIVEN `HeroBlock` registered under `hero` WHEN `siteBlockIsBand('hero')` is asked THEN it answers true
  - GIVEN a band splitting the grid WHEN placed THEN the run below starts at its own first row; the grid test fails without `rowOffset`
- Reference: eed4c3b, 498dede, 4d7140a, 46d7e9f
- [ ] Implement
- [ ] Test

### Task 7: Regions in the contract and the renderer

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008`, `#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009`
- **files**: `lib/Service/PortalRegionResolver.php`, `lib/Service/CmsReader.php`, `lib/Controller/ContentController.php`, `src/site/lib/regions.js`, `src/site/App.vue`, `lib/Settings/portaliq_register.json`, `tests/Unit/Service/PortalRegionResolverTest.php`, `tests/site-regions.spec.mjs`
- **acceptance_criteria**:
  - GIVEN each of the five regions WHEN inherited, overridden and cleared THEN all fifteen cases resolve correctly, and swapping in `empty()` fails the cleared cases
  - GIVEN a page that fills `hero` WHEN rendered THEN exactly one hero and one `h1` appear
  - GIVEN `CmsReader` WHEN it shapes a page THEN `draftBody` is still never projected
- Reference: a5657c6, 7974e5a, 79fdb2c
- [ ] Implement
- [ ] Test

### Task 8: The designer keeps the regions it does not edit

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010`
- **files**: `src/views/PageLayoutDesigner.vue`, `tests/e2e/site-page-editing.spec.ts`
- **acceptance_criteria**:
  - GIVEN a page with a hero, three `main` widgets and `clearedRegions: ["aside"]` WHEN a `main` widget is moved and saved as draft, then published THEN the hero and `clearedRegions` are unchanged in `draftBody` and in `body`
  - GIVEN the designer grid WHEN it loads THEN only `main` widgets are shown
- [ ] Implement
- [ ] Test

### Task 9: The contributed page route

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-page-must-be-reachable-at-its-own-route-req-ptb-011`
- **files**: `src/site/App.vue`, `src/site/components/ContributionPage.vue`, `src/site/components/ContributionsBlock.vue`
- **acceptance_criteria**:
  - GIVEN La Franken's contribution WHEN a visitor follows an index entry THEN `/diensten/{app}/{page}` renders its `richText` and `action` blocks without a second content request
  - GIVEN an entry without a page WHEN rendered THEN it is plain text
  - GIVEN an unknown page WHEN opened THEN the 404 state renders
- Reference: 547fb33
- [ ] Implement
- [ ] Test

### Task 10: Contributed actions, anonymous ownership and the AA check

- **spec_ref**: `openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012`, `#requirement-the-chrome-must-meet-wcag-21-aa-on-what-is-actually-painted-req-ptb-013`
- **files**: `src/site/lib/contributionApi.js`, `lib/Service/PortalObjectWriter.php`, `src/site/lib/contrast.js`, `tests/site-contribution.spec.mjs`, `tests/site-surfaces.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN a signed-out visitor WHEN they submit an anonymous create THEN one request reaches the collection route with no `Authorization` header
  - GIVEN a browser with an admin Nextcloud cookie WHEN it submits the same form THEN `_owner` is not `admin`; the OpenRegister version guard is tested both ways
  - GIVEN `npm run check:surfaces` WHEN it runs on both demo portals at 1440px and 390px THEN it self-tests, then reports zero contrast failures, one `h1` per page, no skipped level and no overflow
- Reference: 4200fd7, a485fad, f1b4294, 9901229
- [ ] Implement
- [ ] Test

## Verification

- The shell snapshot matches the baseline for every portal without new fields.
- `openspec validate portal-theme-blocks-and-contributed-pages --strict` passes.
- Before sync, each ADDED scenario is referenced by a Playwright spec under
  `tests/e2e/` or carries a reason-bearing `@e2e exclude`. Gate-19 reads only
  `openspec/specs/`, so it will not enforce this until the delta is synced.
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` pass once, before push.

## Quality checklist

- PHPUnit covers `PortalTokenCss`, `PortalRegionResolver`, the chain in `PortalThemeResolver`, the footer projection and the `_unowned` guard.
- Newman or contract tests cover the new keys on the public site and page contract (gate-25).
- Playwright covers the header variants, region states, the third footer band, the contributed route and the anonymous submission.
- Every new user-facing string has Dutch and English entries, and blocks keep taking strings as props.
- `docs/` gains a page on portal chrome: header variant, footer content, token overrides and regions.
