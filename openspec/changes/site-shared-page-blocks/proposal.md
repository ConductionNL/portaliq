---
kind: code
depends_on: []
---

# Proposal: site-shared-page-blocks

## Why

An organisation that runs several portals (a resident portal, a business
portal, a reading room) repeats the same blocks on each: the contact band, the
opening hours, the "how we handle your data" section. Today every copy is
typed into every page of every portal. When the opening hours change, an
editor has to find and edit each copy, and the one that is missed tells a
resident the wrong time.

Portaliq matrix row `dem-rm-shared-design-library`, "Maintain one shared set
of styles and page blocks that several portals use, instead of repeating it
per portal.", rated `partial`, `built.state` `built`. Its `built.evidence`,
verbatim:

> several portals can point at the same thematiq token set: lib/Service/PortalThemeResolver.php:104 and :172 read token-sets.json from the theme app, so one style set serves many portals; page blocks are per portal (widget layouts are stored per page), so no shared block library

Its `built.note`, verbatim:

> Owner moved to ConductionNL/portaliq (thematiq lane): the shared token set is built in thematiq; the missing half is a shared library of page blocks, which is portaliq's (thematiq lane, OpenSpec pass 2026-09-27; owner was ConductionNL/thematiq).

The thematiq lane decided the row `decided-no` for thematiq in its own
decisions file (ConductionNL/thematiq `openspec/parity/gap-decisions.json`,
matrix portaliq): "The shared styles half is built (several portals point at
one thematiq set through portaliq PortalThemeResolver). The missing half is a
shared page-block library, and page blocks are portaliq widget layouts, so
thematiq cannot own it". The sibling pass then moved the row's owner to
portaliq. This change is that missing half.

Demand: origin `roadmap`, <https://www.liferay.com/roadmap>. One competitor
is rated `yes`. `liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/sites/site-appearance 'Design Libraries Release Feature (LPD-57283) ... A style book or page fragment created in a design library is available to every site connected to that library'; 'Facilitate Sharing Designs Across Sites (Design Library)' under Now on https://www.liferay.com/roadmap.

Decision `build` in the owner-moves pass of 2026-09-28: a roadmap demand row
plus one competitor rated `yes`, and the demand names the missing half (page
fragments shared across sites), not the built styles half.

## What changes

- **A shared block.** An organisation keeps named blocks of widgets that do
  not belong to one portal. A block is laid out on the same 12-column grid as
  a page.
- **A page places a shared block.** In the page designer, "Shared block" is a
  palette entry. The editor picks one of the organisation's published blocks
  and places it like any other widget.
- **One edit, every portal.** The content API expands the placement into the
  block's widgets when the page is read, so an edit to the block shows on
  every page of every portal that places it, after the next read.
- **The organisation boundary holds.** A block never renders on a portal of
  another organisation, and an unpublished block renders nowhere.
- **Where it is used.** A block's page lists the pages that place it, so an
  editor knows what an edit will touch before making it.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-rm-shared-design-library` | Maintain one shared set of styles and page blocks that several portals use, instead of repeating it per portal. | partial | A library of page blocks shared by the portals of one organisation. The styles half is built through thematiq. |

## Existing work it builds on

- `portaliq-cms` (spec): the `page` schema, its grid body and the cached
  public content reads (`CmsReader`).
- `portal-page-designer` (spec): the layout designer, its palette and who may
  edit pages (`PageEditorService`).
- `operate-portals-per-organisation` (open change): one organisation owning
  several portals, which is the reach of a shared block.

## Out of scope

- Sharing styles. Several portals already share one thematiq token set.
- Sharing blocks across organisations. A block belongs to one organisation.
- Overriding a single widget of a shared block on one page. A page that needs
  a different version places its own widgets.
- Versioning a block. The published block is the one every portal reads.

## Sibling halves

None. The styles half is thematiq's and is built.
