---
kind: code
---

# Proposal: portal-page-composition

## Summary

Make the whole portal page authorable — header, navigation, hero, body, aside
and footer — instead of a fixed shell wrapped around one editable body, and
give portaliq an editor for it. The target is that a portal can be composed to
match a real reference design (docs.conduction.nl) without a developer editing
`App.vue`.

## Why the theme work stopped short

Three portals were themed in one session. Colour is now correct on all of them
and none of them looks like its brand, for one reason: **the shell is markup,
not data.**

- `App.vue` hard-codes `<header>`, the navigation, and a footer whose two bands
  are selected positionally in CSS (`:first-of-type`, `:last-of-type`). A
  portal cannot add a third band, reorder them, put a call-to-action in the
  header, or move its logo.
- Only the page BODY is composed from widgets.
- Every seeded widget already declares `"slot": "body"` — and **nothing reads
  `slot`**. Nine widgets carry it; a grep for a reader returns nothing. The
  hook for regions is already in the data and has never been connected.

So the missing capability is not a grid. The grid exists — `gridX`, `gridY`,
`gridWidth`, `gridHeight` on every widget, the same geometry the admin
dashboard and detail pages already use. What is missing is that exactly one
region is honoured.

## What this changes

**Regions.** A page is `regions: { header, hero, main, aside, footer }`, each a
widget list with the geometry that already exists. `slot` stops being decorative
and becomes the region key.

**Inheritance.** Region defaults live on the PORTAL, so every page gets a shell
without repeating it, and a page may override any region. A portal-level header
is what makes twenty pages consistent; a page-level override is what makes the
landing page different.

**An editor in portaliq**, not a new one from scratch. The fleet already ships a
drag/resize grid editor — `CnDashboardGrid`, OpenBuild edit mode, an "add
widget" modal and per-type configuration forms — driving admin dashboards and
detail pages. Pointing it at the public page model is far cheaper than a second
editor and gives public pages the authoring UX admin pages already have.

**The blocks the reference needs.** docs.conduction.nl is four compositions we
do not have yet: a brand header (logo, navigation, locale, call-to-action), a
hero band with an eyebrow label, two buttons and an illustration slot, a card
grid with icons, and a footer of link columns above a legal bar.

## What "pixel match" should mean

Pixel-matching an arbitrary site is not a capability worth building: it turns
the renderer into a website builder, every block grows options nobody can
review, and each new option is a way for an author to build something
inaccessible.

Pixel-matching **one named template** is worth building. `conduction-docs`
composes the blocks above into the docs.conduction.nl layout, ships as portal
region defaults, and is the conformance target — if the template cannot be
expressed in the region model, the region model is incomplete, which is exactly
the feedback this change needs.

## Prior art, and what we take from it

Researched rather than assumed. Three open-source visual editors are close to
our situation, and the useful thing is that they agree on the shape of the UI:

| tool | what it is | what we take |
| --- | --- | --- |
| [Puck](https://puckeditor.com/docs) | embeddable React visual editor; you register your OWN components with a field config that maps to their props | the model closest to ours — we already have a widget registry with per-type config forms. Its component/field registration is what our registry should look like from the editor's side. Same-origin iframe previews for viewport sizes; permissions per component (duplicate/delete) |
| [GrapesJS](https://gjs.market/blogs/grapesjs-the-complete-guide-to-the-open-source-web-builder-f) | mature open web builder | block manager (a categorised library of insertable snippets), device manager (responsive preview switching), pluggable storage, plugin API |
| [Plasmic](https://openapps.pro/apps/plasmic) | visual builder over an existing React codebase | canvas renders YOUR real production components in an isolated sandbox — the page you edit is the page that ships, not an approximation |

The convergent UI is: **a categorised block library on the left, the real page
on a canvas in the middle, an inspector for the selected block on the right, a
layer tree for structure, a breakpoint switcher, and undo/redo.** That is the
layout to build, because it is the one authors have already learned elsewhere.

The design priority is that the canvas renders the REAL blocks — the same
components the public site mounts — so what an author sees is what a visitor
gets. An editor that previews an approximation is how a page ships broken while
looking fine in the tool.

## Affected Projects

- [ ] `portaliq` — region model, region-aware renderer, the editor surface, the
      `conduction-docs` template.
- [ ] `nextcloud-vue` — the four new public blocks, and whatever the editor
      needs to describe a block's fields to an author.
- [ ] `openregister` — no change; regions live in the existing page objects.

## Out of scope

- Freehand layout (absolute positioning, arbitrary CSS per block). The grid is
  the constraint that keeps output accessible and responsive.
- Authoring raw HTML or CSS in the editor.
- A second theming mechanism. Colour comes from the token set and the shared
  public bridge; this change is about structure only.
