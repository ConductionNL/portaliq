---
kind: code
---

# Proposal: portal-page-designer

## Summary

Make a portal page editable the way an app's dashboard already is: place
widgets on the shared 12-column grid, move and resize them by direct
manipulation, and reach that editor from the site itself through a floating
button in the bottom-right corner.

Today a page's `body.widgets` — the same manifest-v2 placement shape
`WidgetGrid.vue` renders and OpenBuild authors — can only be changed by typing
JSON into the generic detail form. Every other app in the fleet that places
widgets (LaunchPad, Buildiq) does it by dragging them.

## Motivation

The CMS half of Portaliq is finished on the read side and unfinished on the
write side. `openspec/specs/portaliq-cms/spec.md` already fixes the grid
geometry, the public allow-list and the markdown-as-source contract; a portal
editor can consume all of it and still cannot lay out a page without hand-
editing an array of `gridX`/`gridY` integers.

That gap is not evenly felt. An editor at a municipality is the person this
product is for, and JSON is the one editing surface they cannot use — so the
practical answer today is "ask a developer", which is the workflow the CMS
exists to remove.

The shared library already ships the machinery: `CnDashboardGrid` (GridStack
drag/resize plus keyboard repositioning), `CnAddWidgetModal` (the catalogue)
and per-widget `Cn*WidgetForm` editors. Nothing here is a new grid; it is the
fleet's grid, pointed at a page object.

## Affected Projects

- [x] `portaliq` — page draft field, configurable editor groups applied to the
      schema's authorization block, an editing-context probe, a floating
      editor entry point on the site, and a grid page designer in the app.

## Design notes

**The editor lives in the Nextcloud app, not in the site bundle.** The site
bundle is downloaded by an anonymous visitor on a phone before anything
renders and is held under a 400 KiB budget that `webpack.site.js` enforces as
`hints: 'error'`. GridStack, a widget catalogue and a set of property forms do
not belong on that download. The site's contribution is the ENTRY POINT: a
floating button that resolves the page being viewed and hands it to the
designer.

**Writes go straight to OpenRegister** (ADR-022). The designer PUTs the page
object through OpenRegister's object API like every other write in the app —
no CRUD wrapper controllers, none of the pass-through code gate-13 exists to
catch.

**Which is why authorization has to move into the schema.** The `page` schema
declares `read` rules only, and OpenRegister's default-closed enforcement is
off by default, so today any authenticated user can write a page. Naming the
editor groups in the schema's `create`/`update`/`delete` rules is what makes
the setting real: the check then runs where the write happens rather than in
the UI that offers it. The groups stay editable in Portaliq's admin settings —
saving them rewrites the block, and OpenRegister's `GroupProvisioner` creates
any group that does not exist yet.

**Draft and published are separate bodies on one object.** `draftBody` holds
work in progress; `body` is what the public API projects. `CmsReader::shapePage()`
copies named keys, so a draft cannot leak by omission — and the test for that
asserts the leak, not the shape.

## Risks

- **A UI-only permission check is not a permission check.** The floating button
  and the designer both ask "may I edit", but the answer that matters is
  OpenRegister's on the write. The test proves a non-editor's PUT is refused,
  not that the button was hidden.
- **Rewriting a live schema's authorization block from a settings save** can
  lock out the very editors it configures. The write is additive to the
  existing `read` rules, admins bypass RBAC entirely, and an empty setting
  restores admin-only rather than open-to-all.
- **A widget in the catalogue that cannot render publicly** is a trap: the
  editor places it and the page shows an inert placeholder. The palette shows
  the whole catalogue — it is a real catalogue, not a curated one — and marks
  the non-public entries with the reason, which is a smaller surprise than a
  widget that is missing for no stated reason.
