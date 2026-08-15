---
kind: code
---

# Proposal: portal-shared-runtime

## Summary

Delete Portaliq's React portal and rebuild it on the shared manifest runtime:
`bootstrapCnApp({ host: 'public' })`, manifest-v2 portal pages with grid
placement, the communal widget catalog filtered by `public: true`, markdown
pages via `CnWikiPage`, and journeys mounted in-page. Removes
`webpack.portal.js` and `react` from the dependency set.

Chain link 7 of `hydra/openspec/changes/portaliq-phase-two`. Implements the
Portaliq half of ADR-084.

## Motivation

`src/portal/` is 1,208 lines of JSX across seven files —
`App.jsx`, `PageView.jsx`, `CollectionTable.jsx`, `SchemaForm.jsx`,
`ActionFieldsForm.jsx`, `InboxPage.jsx`, `RichText.jsx` — reimplementing what
`CnPageRenderer` already does for every other app in the fleet. It has no grid,
mounts none of the 40 communal widget types, and its `SchemaForm.jsx` supports
none of the `steps[]` / `visibleWhen` / `fieldValidation` grammar the manifest
already defines and `CnFormPage` already renders.

It also carries a build hazard documented in `webpack.config.js` itself: the
two configs share an output directory, guarded by
`output.clean = { keep: /^portaliq-portal\.js/ }`, because an admin-only
rebuild otherwise deletes the portal bundle and the portal then serves a bare
`<div>` with a 404 on its script and **no console error**.

## Affected Projects

- [ ] `portaliq` — delete `src/portal/*.jsx` and `webpack.portal.js`; boot the
      shared runtime; portal manifest becomes manifest v2; remove `react` and
      `react-dom`.

## Design notes

**The portal manifest becomes a manifest-v2 document**, so a `portalPage`
object and an app manifest describe pages identically. `PortalContributionRegistry`
and `PortalManifestNormaliser` keep their role — what changes is the shape they
normalise to.

**Every page type is available**, including `widgets[]` grid placement on any
page type. The grid is the manifest's, unchanged — not a portal variant.

**Widgets come from `dashboardWidgetRegistry`**, filtered to `public: true`.
Portaliq MAY add host overrides the way LaunchPad does; it MUST NOT fork the
catalog.

**Bundle weight becomes a first-class constraint.** This is a public,
first-visit, mobile-visited surface, unlike an in-Nextcloud SPA behind a login.
Widget code and `CnJourney` are route-split, and the budget fails the build.

## Risks

- **A rewrite of the visible product.** Parity is proven page by page against
  the React portal, not asserted from "it renders".
- **The `output.clean` hazard disappears with the second config** — but until
  it does, a partial migration is the state in which it is most likely to bite.
- **Bundle weight regression is invisible without a failing budget.** Measured
  on transferred bytes, since the build's own emitted-size report is not what a
  visitor pays.
