# Design: site-shared-page-blocks

Read at portaliq `development` `4f460b3`.

## Where a block lives today

- `lib/Settings/portaliq_register.json`, schema `page`: `portal` (required,
  "content is always resolved within a site"), `body` and `draftBody`, where a
  `grid` body is a `widgets` array of manifest-v2 widget entries (`id`,
  `widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth`, `gridHeight`, `props`).
  Every widget belongs to exactly one page of exactly one portal.
- Schema `portal`: `organisation`, described as "The tenant this portal
  belongs to. One Organisation may own several portals."
- `lib/Service/CmsReader.php`: `page()` (:201) reads the published page for
  a portal and route and caches the shaped result per portal, kind, route,
  locale and audience; `shapePage()` (:437) copies each widget entry and sorts
  them by row and column.
- `lib/Listener/CmsCacheInvalidationListener.php`: `CMS_SCHEMAS` is
  `['portal', 'menu', 'page', 'glossaryTerm']` (:65); a write clears the cache
  of the portal named by the object's `portal` (or `slug`) field (:108).
- `lib/Controller/ContentController.php:335` `page()` resolves the serving
  portal through `PortalResolver::resolve()` and answers `CmsReader::page()`.
  The same reader serves the headless content API
  (`appinfo/routes.php:75-86`).
- `src/views/PageLayoutDesigner.vue`: the grid designer; it saves
  `draftBody: { type: 'grid', widgets }` and publishes by promoting the draft.
  `src/dialogs/WidgetPaletteDialog.vue` offers the catalogue of
  `src/lib/pageWidgetCatalogue.js`, whose public half is derived from the
  renderer's allow-list in `src/site/components/WidgetGrid.vue`
  (`publicWidgetKeys()`, :197).
- `lib/Service/PageEditorService.php:243` `applyToSchema()` writes the editor
  groups into the `page` schema's authorization for the write actions.

## D1. A shared block is its own object, owned by an organisation

New schema `sharedBlock` in `lib/Settings/portaliq_register.json`:
`title`, `description`, `organisation` (required), `status` (`draft`,
`published`), `widgets` (the same widget entry shape as a page's grid body)
and `draftWidgets` (the unpublished work, the same draft and publish contract
as `page.draftBody`). It has no `portal`: that is the point of it.

Alternative considered: a page flagged as a template and copied into each
portal. Rejected: a copy is exactly the thing that goes stale. The block has
to be read, not copied.

## D2. A page places a block by reference, and the reader expands it

A page's grid body places a block with a widget entry whose `widgetKey` is
`sharedBlock` and whose `props.block` is the block's uuid. The entry keeps
its own `gridX`, `gridY`, `gridWidth` and `gridHeight`: the block occupies
that rectangle.

`CmsReader::shapePage()` gains the organisation of the portal it shapes for
and, for each `sharedBlock` entry, reads the block once per request
(`_rbac: false`, the same system read the reader already does for pages) and
puts the block's shaped widgets into `props.widgets` of the placement. The
placement stays one entry on the page, so nothing on the page reflows and the
headless content API returns the expanded widgets in one answer.

`src/site/components/WidgetGrid.vue` adds `sharedBlock` to its public
allow-list and renders it as a nested grid over `props.widgets`, with the same
renderer and the same placeholder rules as the page grid.

## D3. The organisation boundary is checked at the read, fail closed

A block expands only when its `status` is `published` and its
`organisation` equals the organisation of the portal being served. In every
other case, including a block that no longer exists, the placement expands to
an empty `props.widgets` and a `props.unavailable: true` marker, and the
public renderer draws nothing for it. A missing or foreign block and an
unpublished one answer the same, so the content API is not an existence
oracle for another organisation's blocks.

## D4. An edit to a block clears every portal that may show it

`sharedBlock` joins `CMS_SCHEMAS`. For a `sharedBlock` write the listener
has no `portal` field to read, so it asks `PortalResolver` for the portals of
the block's `organisation` and calls `CmsReader::invalidate()` for each. A
failure is logged and never fails the write, as today.

## D5. Editing a block uses the page designer

- A "Shared blocks" index and detail in `src/manifest.json`, beside "Pages"
  and "Menus", scoped to the organisation.
- The layout designer opens a block the same way it opens a page, on a route
  `/shared-blocks/:id/layout`, saving `draftWidgets` and publishing into
  `widgets`.
- The palette gains one entry, "Shared block", that asks which of the
  organisation's published blocks to place and writes the `sharedBlock`
  widget entry.
- The block's detail page lists the pages that place it, read from the
  organisation's portals' pages whose body carries the block's uuid.

## D6. The people who edit pages edit blocks

`PageEditorService::applyToSchema()` writes the same editor groups into the
`sharedBlock` schema's authorization, so the group that may edit pages may
edit shared blocks, and nobody else. No new setting.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The block and its fields | Declarative, a schema in the register | Data. |
| Who may edit a block | Declarative, the schema's authorization, written by the existing editor-group setting | The same rule as pages. |
| Expanding a placement and the organisation check | Imperative, `CmsReader::shapePage()` | A read across two objects with a boundary check. |
| Clearing the cache of every portal of the organisation | Imperative, the existing cache listener | A fan-out a declaration cannot express. |

## Seed data

`sharedBlock` "Contact and opening hours" for organisation `dev-org`,
published, with a markdown widget carrying the town hall's address and hours,
placed on the home page of two seeded portals of that organisation.

## Risks

- **A block edit reaches pages the editor did not look at.** That is the
  feature; the block's detail page lists every page that places it before
  the editor publishes.
- **A deleted block leaves holes.** A placement of a missing block renders
  nothing on the public page, and the designer shows it as "Shared block not
  available" so an editor can remove it.
- **Read cost.** Each placement is one extra read per uncached page view. The
  shaped page is cached as today, so the cost is paid once per portal, route
  and locale until the next write.

## What it deliberately does not do

- It does not let a page override one widget of a block.
- It does not share blocks across organisations.
