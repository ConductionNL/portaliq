# Design: portal-theme-blocks-and-contributed-pages

## Context

Branch `feat/portal-nextcloud-signin` (head e97f3c8, 45 commits from merge base
9789e18) carried four OpenSpec changes at once: page composition, theme
integration, contribution actions and traffic analytics. Its own continuation
plan (`docs/continuation-plan.md` on the branch) marked every task closed. None
of it reached `development`, and since then `development` moved in 22 of the
files the branch touched.

This design records what the branch decided, which commit decided it, and where
this change keeps or departs from that decision. Read a commit with
`git show <sha>` on a clone that has fetched the branch.

### What exists on `development` today

| Area | `development` | Branch |
| --- | --- | --- |
| Theme layers | one token set, vendored NLDS sheets, `nlds-fonts.css` | bridge, set chain, portal overrides, `site-theme.css` (1,430 lines) |
| Header | hard-coded in `App.vue`, double bar only | `BrandHeader.vue`, `single` or `double` |
| Hero | `CnSiteHero` from `@conduction/nextcloud-vue` | `HeroBlock.vue` with eyebrow, lead, two actions |
| Footer | hard-coded in `App.vue`, two positional bands | `FooterColumns.vue`, bands by role, `portal.footer` |
| Regions | none; `slot` stored and unread | `PortalRegionResolver`, `regions.js`, `portal.regions` |
| Contributed pages | index entries are plain text | `/diensten/{app}/{page}`, `ContributionPage.vue` |
| Page editing | #281 designer on `body.widgets` and `draftBody` | its own editor on `/editor` |
| Site bundle | ten `@utrecht/*-css` imports in `src/site/main.js` | one import, dist CSS excluded |

## Goals

- One rendering of the shell, driven by data, that reproduces today's shell for
  every portal that sets nothing new.
- No colour decided in portaliq. Colour comes from thematiq or from the
  portal's own allow-listed overrides.
- A contributed page a visitor can open and act on.

## Non-goals

- A second editor. The #281 designer stays the only one.
- Pixel parity with docs.conduction.nl. The branch measured it (header 11/11,
  hero 24/24, cards 24/24, footer 41/44) as a test of the token model, not as a
  product goal.
- Dark mode, the theme picker, contrast verdicts and shared themes. See the
  sibling requirements added to `nldesign-theme-integration`.

## Decisions

### D1. Theme tokens are consumed in four layers, in a fixed order

Link order in `templates/site.php`: thematiq `css/public-bridge.css`, then the
token set chain parent first, then the vendored NLDS sheets, then
`css/site-theme.css`, then the portal's own `:root` override block.

- The bridge maps `--nldesign-color-*`, which every set defines, onto the
  `--utrecht-*` roles the vendored components read. Without it a set loads and
  paints nothing. Source: thematiq#355 and the asset partial in 19fbcd6.
- The chain comes from `PortalThemeResolver::stylesheetChainFor()`, capped at
  four hops (46d7e9f). Parent first is the behaviour, so a test pins the order.
- Portal overrides come from `PortalTokenCss` (c06adac): a name pattern of
  themed families, a value pattern of plain characters, and a forbidden list led
  by `url(`. Rejected declarations are dropped. A portal that loses one override
  has a styling bug; a portal that renders attacker CSS has an incident.
- Overrides are data on the portal record, not tokens in this repository, so
  `nldesign-theme-integration`'s rule that portaliq ships no tokens still holds.

Alternative considered: writing component roles into each of the 46 token sets.
Rejected by thematiq#355 itself, since one mapping beats 46 copies.

### D2. `site-theme.css` holds token references and no colour literals

Every rule the site adds names a `--nldesign-*` token (254 lines on the branch
reference one). Bands that paint themselves name their own foreground (93a0ecd),
because a hero title inheriting black on cobalt measured 2.31:1.

**Departure from the branch.** The branch kept 7 hex fallbacks, such as
`var(--utrecht-document-background-color, #fff)`, so light mode stayed
byte-identical while the bridge was not yet linked (03fdd5f). This change links
the bridge first, so the `--utrecht-*` token is always defined when a theme
resolves. The last fallback becomes a keyword. A portal whose theme does not
resolve then renders unstyled, which is what `portaliq-cms` already requires.
Enforce it with stylelint `color-no-hex` and `color-named: never` on
`css/site-theme.css`.

Two traps the builder will meet again:

- **Vendored specificity.** Footer rules are `.ac-footer section:first-of-type
  <element>`, specificity (0,2,2) to (0,3,3). An obvious (0,2,0) selector loses
  while the token resolves and the declaration sits in the CSSOM (34cde3e).
  `CSS.getMatchedStylesForNode` names the winner.
- **Tokens declared on the element.** `.ac-header` declares
  `--navigation-bar-height` on itself, so bridging it at `:root` does nothing.
  Bridge it on `.pq-site .ac-header` (fd37778).

### D3. The block model: the shell owns data, blocks own markup

- `brandHeader`, `hero` and `footerColumns` are Vue blocks mounted by region
  (7974e5a). `navigate` and `signout` are emitted, because a block that reaches
  for the router cannot mount in the designer canvas or a Docusaurus build.
- Every string is a prop defaulting to the Dutch text the shell ships today, so
  nothing changes for a portal that sets none (7974e5a, task 3.5 of the branch's
  `portal-page-composition`).
- `headerVariant` lives on the portal, not the theme. Two portals can share a
  palette and disagree on chrome. Unknown values fall back to `double`
  (045b168).
- Footer bands carry `pq-footer__band--content` and `pq-footer__band--legal`,
  and every positional selector reads the class instead. Each role reads its own
  padding token: the vendored sheet scoped one shared token positionally, and a
  third band moved the legal bar's padding from 0px to 88px (423d7df).
- Hero: the eyebrow is a `p`, and actions are capped with `.slice(0, 2)`
  (4d7140a). The lead is the `subtitle` prop; the branch found pages authoring
  `description`, which nothing read (eed4c3b).
- Card icons are names resolved against a fixed map, so hostile names render
  nothing (4d7140a). Scope card rules to `.pq-grid`: `.ac-card` is also the
  hero's search box, and an unscoped padding rule broke it (045b168, 93a0ecd).
- Grid runs split by a full-bleed band carry a `rowOffset`, so a run does not
  reserve rows for widgets rendered elsewhere. It measured a 320px void before
  the fix (46d7e9f, `src/site/lib/gridPlacement.js`).
- `style` and `class` are stripped from authored props for grid and shell
  blocks, from one helper (a485fad, `src/site/lib/blockProps.js`).

### D4. Five closed regions, three states each

The resolver is `PortalRegionResolver::resolve()` and `resolveRegions()` in
`src/site/lib/regions.js` (a5657c6, 7974e5a). The rules kept from the branch:

- The list is closed. A misspelt slot is reported by name.
- `slot: "body"` means `main`, so no data migration is needed.
- Resolution uses `array_key_exists` and `Object.hasOwn`, never `isset`, `??`
  or truthiness. The branch proved it by swapping in `empty()`, which failed
  exactly the cleared case.
- `body.widgets` stays in the contract beside `body.regions`, because the
  Docusaurus plugin and the e2e grid check read it.
- The public body is built from the resolved regions, not the flat list. On the
  branch, a portal that filled `hero` was silently ignored until this was fixed
  (79fdb2c).

**Departure from the branch.** On the branch a page's regions were derived by
grouping its widgets by `slot`, and the editor saved by flattening regions back
into slot-tagged widgets (`PageRegionsController::flatten()`). A region with no
widgets therefore had no key, so a page could never persist "cleared". This
change adds `body.clearedRegions`, a list of region names, as the only way to
say a region is empty on purpose.

The branch recorded six limits of the model (79fdb2c). They stay true here:
a page replaces a region and cannot insert into one; there is no sixth region;
a block cannot span two regions; there is no per-breakpoint placement; footer
bands live inside one block; and there is no conditional visibility.

### D5. The #281 designer edits `main` and nothing else

`PageLayoutDesigner.vue` loads every widget into one `CnDashboardGrid` and
defaults `slot` to `body`. Once the header and hero are widgets, that grid would
show them and could move or drop them. The designer filters to `main` on load,
and on save merges the untouched widgets and `clearedRegions` back into both
`draftBody` and `body`. Editing other regions is a later change to the designer,
not this one.

### D6. Contributed pages route from data already loaded

- `/diensten/{app}/{page}` resolves from the contributions the site already
  fetched. Asking the CMS for that route first would be a guaranteed 404
  (547fb33).
- `richText` renders through the markdown block the CMS uses. `action` renders a
  form posting only declared fields, the same whitelist the server applies.
- The route choice follows the action's type, in `contributionApi.js` (4200fd7).
  A `create` goes to the collection route, which admits a caller with no bearer.
  The branch first posted everything to the forward route, which refuses a
  create with or without a session and made accepted submissions look like 401s.
- `isAnonymouslySubmittable()` requires `type: create` and `anonymous: true`.
- `PortalObjectWriter::createAnonymousObject()` passes `_unowned: true`. The
  branch measured `_owner: admin` from a browser holding an admin cookie, on a
  form that says it is not tied to an account. openregister#2548 added the
  argument. An unknown named argument is a fatal error, so guard the call on the
  installed OpenRegister version.
- The route only fits the budget once the duplicate design system CSS is gone.
  Drop the nine `@utrecht/*-css` imports the linked sheets already cover, keep
  `@utrecht/skip-link-css`, and exclude `@conduction/nextcloud-vue`'s dist CSS
  with the local loader in `build/empty-css-loader.js` (076bd6e).

## Accessibility (WCAG 2.1 AA)

The branch's surface check (`tests/site-surfaces.spec.mjs`, `npm run
check:surfaces`) is the instrument, and its lessons are the design:

- **Measure against what paints.** Walk to the first ancestor with a painted
  background. Comparing against the nearest named band once reported a passing
  label at 1.06 and the fix made a working search form invisible.
- **Composite alpha first.** `rgba(255,255,255,0.22)` on navy scored 17.85:1
  without compositing (a485fad). Scrims below 30% alpha are skipped.
- **One rule, two consumers.** Keep the audit in `src/site/lib/contrast.js` so a
  future designer warning and the CI check cannot drift.
- **Self-test before judging.** Inject low contrast, a second `h1` and a 3000px
  element, and refuse to report if any goes undetected (9901229).
- **Heading outline.** Only downward jumps count. The check found `/aanvragen`
  rendering `h1` then four `h3` cards; the fix was the grid's `headingLevel`
  data, not code (f1b4294).
- **Keyboard and screen reader.** Decoration is `aria-hidden` with no tab stops
  (498dede). Menus render once per header variant, so no link is announced
  twice (045b168). The site name is a `span`, so each page keeps one `h1`.
- **Deviations from the reference that are AA fixes, keep them.** The
  reference's orange primary button is white on `#F36C21` at 3.01:1; the branch
  kept the orange and darkened the label to 5.93:1 (eed4c3b). Footer links take
  85% white on navy at 13:1, not the 4.28:1 accent colour (34cde3e).

## Conflicts the builder must expect

A merge of the branch into `development` conflicts in these 22 files, measured
with `git merge-tree` on 2026-09-14:

`appinfo/info.xml`, `appinfo/routes.php`, `lib/AppInfo/Application.php`,
`lib/Controller/ContentController.php`, `lib/Controller/TrafficController.php`,
`lib/Service/CmsReader.php`, `lib/Service/PortalThemeResolver.php`,
`lib/Settings/portaliq_register.json`,
`openspec/changes/portal-traffic-analytics/tasks.md`, `package.json`,
`src/icons.js`, `src/site/App.vue`, `src/site/components/WidgetGrid.vue`,
`src/site/lib/authApi.js`, `src/views/AdminRoot.vue`, `templates/site.php`,
`tests/Unit/Controller/ContentControllerTest.php`,
`tests/Unit/Controller/PortalPageControllerTest.php`,
`tests/Unit/Controller/SessionControllerTest.php`,
`tests/Unit/Controller/TrafficControllerTest.php`,
`tests/traffic-client.spec.mjs`, `webpack.traffic.js`.

For this change only the renderer files matter. How to handle them:

- **`src/site/App.vue`.** Start from `development`. It has #281's edit button,
  #559's sign-in states, landing capture and the publication detail route. Move
  its header and footer markup into the blocks; do not paste the branch's
  `App.vue` over it.
- **`templates/site.php`.** The branch moved asset links into
  `templates/partials/site-assets.php` so an editor document could share them.
  With no branch editor, keep one template unless a second consumer appears.
  Open PR #516 also edits this file and `PortalPageController.php`.
- **`lib/Service/CmsReader.php`.** `development` added the `draftBody`
  projection and the OpenRegister context fix from #281. Add region grouping
  after the widget shaping, and never project `draftBody`.
- **`lib/Settings/portaliq_register.json`.** The branch diff is 4,123 lines,
  mostly reformatting. Add the four portal properties by hand, each with a
  description (gate-51).
- **`lib/Service/PortalThemeResolver.php`.** `development` has `stylesheetFor`,
  `logoFileFor` and `nldsStylesheetFor`. Add `stylesheetChainFor` beside them.
  Leave `catalogue()` and `tokenValuesFor()` to `nldesign-theme-integration`.
- **`src/site/components/WidgetGrid.vue`.** Register `HeroBlock` after the
  library spread under the same `hero` key, so `siteBlockIsBand` still treats it
  as a band (eed4c3b).
- **`package.json`.** Add `check:site-grid`, `check:site-regions`,
  `check:site-contribution` and `check:surfaces` to what `development` has; do
  not take the branch's `check:specs` line, which drops five newer checks.

The traffic, session and admin files in the list belong to work already
shipped. Leave them as they are on `development`.

## Risks and trade-offs

- [Linking the bridge changes colours on portals whose set lacks component
  roles] → capture computed colours of the eleven painted surfaces on both demo
  portals before and after, as 03fdd5f did, and treat any change as a finding.
- [Removing hex fallbacks exposes an unresolved theme as unstyled] → this is the
  required behaviour; `PortalThemeResolverTest` already pins the unstyled case.
- [`clearedRegions` is a new field nothing writes yet] → the designer preserves
  it (D5), and seed data can set it; an author interface is later work.
- [The `_unowned` guard misreads a version] → test both branches of the guard
  with a stubbed version, and assert the owner on a live create.

## Migration plan

No data migration. `slot: "body"` already means `main`, and a portal without
the new properties renders the default shell. Deploy order: thematiq with #355
(already on `development`), OpenRegister with #2548 (already on `development`),
then this build. Rollback is a revert of the build commits.

## Open questions

- Does the Docusaurus plugin (`ConductionNL/docusaurus-plugin-portaliq`) want
  `body.regions`, or keep reading `body.widgets` only?
