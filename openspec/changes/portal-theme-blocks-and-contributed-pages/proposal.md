---
kind: code
depends_on: [nldesign-theme-integration, portal-page-designer]
---

# Proposal: portal-theme-blocks-and-contributed-pages

## Summary

Finish the portal design programme that was built on `feat/portal-nextcloud-signin`
between 2026-08-17 and 2026-08-18 and never merged. A portal's header, hero and
footer become blocks placed in regions, every surface they paint reads a
thematiq token, and the pages a contributing app publishes get their own route.
This change is the spec for a later build. The branch is the reference, not the
thing to merge.

## Motivation

The branch holds 45 commits. Three of its threads already reached `development`
by other routes: the sign-in fix as #559, traffic analytics as #435 to #449, and
page editing as #281. The rest is stuck. It conflicts with `development` in 22
files, so a merge is no longer a realistic way to land it.

What a visitor meets on `development` today:

- **The shell is markup.** `src/site/App.vue` hard-codes the header and the footer
  (1,438 lines). Only the page body is composed from widgets. Every seeded
  widget declares `slot`, and nothing reads it.
- **The footer is styled by position.** The vendored `nlds-app.css` selects its
  two bands with `:first-of-type` and `:last-of-type:not(:only-of-type)`. A
  third band restyles the other two.
- **The theme stops at colour.** `templates/site.php` links one token set. It
  does not link thematiq's `css/public-bridge.css` (thematiq#355, merged), so a
  set that only declares `--nldesign-color-*` changes nothing the vendored
  components paint. A portal cannot differ from its theme by one accent colour.
- **Contributed pages cannot be opened.** The contributions index names pages a
  leaf app publishes, and no route renders them. Their entries stay plain text.

What the branch proved, by building it and measuring the result:

- A header, hero and footer can be blocks without changing what an existing
  portal renders. `tests/shell-snapshot.mjs` compared six pages before and after
  the move, and all six were unchanged (7974e5a).
- Every painted surface can resolve through a token. Changing
  `--utrecht-document-background-color` alone then repaints all eight surfaces,
  where before it repainted none (03fdd5f).
- A contributed page fits the 400 KiB site budget, but only once the design
  system's CSS stops shipping twice: 393.6 KiB down to 358.3 KiB (076bd6e), then
  370.1 KiB with the route in (547fb33).

## Affected projects

- [ ] Project: portaliq, the site renderer, its content contract, the page
  designer's save path and the portal schema.
- [ ] Project: thematiq, no change. This change consumes `css/public-bridge.css`
  and the component tokens thematiq#355 already ships.
- [ ] Project: openregister, no change. This change passes the `_unowned`
  argument openregister#2548 already added to `ObjectService::saveObject()`.

## Scope

### In scope

- The site's token layer: the bridge, the set, a portal's own overrides, and a
  theme that extends another.
- `css/site-theme.css`, holding only token references, no colour literals.
- Header, hero and footer as blocks with a portal-chosen header variant.
- Five closed regions with inherit, override and clear per page.
- The page designer keeping widgets outside `main` intact when it saves.
- The `/diensten/{app}/{page}` route, its blocks and its actions.
- WCAG 2.1 AA on the chrome, checked against what is actually painted.

### Out of scope

- The branch's page editor (`src/editor/`, `templates/editor.php`,
  `PageRegionsController`). #281 shipped the designer instead.
- Traffic analytics. #435 to #449 shipped it.
- Sign-in. #559 shipped it.
- The theme picker, contrast verdicts and shared theme adoption. They belong to
  `nldesign-theme-integration`, which this change amends with sibling
  requirements rather than duplicating.
- The canal scene above the footer (`FooterCanal.vue`, `footer.decoration`).
  It is Conduction's own illustration and belongs in the identity repository.
- The `conduction-docs` template (`src/site/lib/templates.js`). A template is
  content for one portal, not a capability of the renderer.
- Dark mode. It was linked and withdrawn twice on measurement (ae48b96).

## Approach

Rebuild from `development`, using the branch commits as the reference for each
decision. Do not cherry-pick: every conflicting file moved since the branch
point, and `App.vue` alone took 11 commits, among them the designer entry point
of #281 and the sign-in of #559. `design.md` names each decision and the commit
it came from.

## New dependencies

None.

## Impact

- **Content contract.** `site` gains `headerVariant`, `footer`, `regions` and
  `auth.register`. A page body gains `regions`, `unknownRegions` and
  `clearedRegions`. `body.widgets` stays as it is, so the Docusaurus plugin and
  any other consumer keep working.
- **Portal schema.** `portal` gains `headerVariant`, `footer`, `regions` and
  `tokens`. Each needs a schema property. An undeclared field is accepted,
  echoed and not stored (045b168).
- **Page designer.** `PageLayoutDesigner.vue` edits only `main` and writes the
  other regions back untouched.

## Cross-project dependencies

- thematiq#355 (merged): `css/public-bridge.css`, `css/fonts-conduction.css`
  and the `--nldesign-header-*`, `--nldesign-hero-*`, `--nldesign-card-*` and
  `--nldesign-footer-*` tokens.
- openregister#2548 (merged): `saveObject(..., _unowned: true)`.

## Risks

### Risk 1: the rebuild regresses an existing portal

**Severity**: High

**Mitigation**: capture `tests/shell-snapshot.mjs` output on `development`
before the first commit, and compare after each task. The branch did exactly
this and caught a scoped-style hash that changed every build.

### Risk 2: the designer deletes the header

**Severity**: High

**Mitigation**: `PageLayoutDesigner.vue` loads every widget into one grid today.
Once header and footer are widgets, a save from that grid can move or drop them.
REQ-PTB-010 requires the designer to edit `main` only, with a test that saves
and reads the other regions back.

### Risk 3: authored page data styles the page

**Severity**: Medium

**Mitigation**: `style` and `class` are Vue fallthrough attributes. The branch
found they did not reach the DOM only because the blocks had fragment roots.
REQ-PTB-007 strips both keys before any block mounts.

### Risk 4: the site bundle breaks its budget

**Severity**: Medium

**Mitigation**: remove the nine redundant `@utrecht/*-css` imports first, as
076bd6e did. `webpack.site.js` fails the build above 400 KiB.

## Rollback strategy

Every addition is additive to the contract. A portal with no `regions`,
`tokens`, `footer` or `headerVariant` renders the default shell, which matches
today's markup. Reverting the build commits restores the hard-coded shell, and
stored portal fields are then ignored rather than harmful.

## Open questions

- Should `conduction-docs` ship as seed data for the Conduction client portal?
  It is left out here because it is content.
