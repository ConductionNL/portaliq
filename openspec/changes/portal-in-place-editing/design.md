# Design: portal-in-place-editing

Read at portaliq `development` `c9319d26` (29 Sep 2026).

## Where editing lives today

- `lib/Settings/portaliq_register.json`, schema `page` (0.4.0): `body` and
  `draftBody` are `{type: grid|markdown, markdown?, widgets[]}`, a widget is
  `{id, widgetKey, slot, gridX, gridY, gridWidth, gridHeight, props}` on 12
  columns. Schema `menu` (0.1.0): `items[]` of `{order, name, link, ...}`, read
  public, no write rules.
- `src/views/PageLayoutDesigner.vue` (route `/pages/:id/layout`): `CnDashboardGrid`
  from nextcloud-vue, palette `src/dialogs/WidgetPaletteDialog.vue` over
  `src/lib/pageWidgetCatalogue.js`, an inspector with fields introspected from Vue
  props, save as a full-object PUT to `/apps/openregister/api/objects/portaliq/page/{id}`.
  `load()` reads only `body.widgets`; `publish()` writes `body: {type: 'grid'}`.
  No undo, no version check.
- `src/site/components/WidgetGrid.vue`: the public renderer, its own CSS grid
  over `PUBLIC_WIDGETS`, bands full width, one column below 768px.
- `src/site/components/SiteEditButton.vue` links an editor to the admin
  designer when `GET /api/cms/editing-context` (`CmsEditorController`) says
  `canEdit`. `PageEditorService::mayEdit()` (admin or a member of
  `editor_groups`) decides; `applyToSchema()` writes those groups into the
  `page` schema's authorization, which is where a write is really refused.
- `webpack.site.js` fails the site build above 410 KiB; `portaliq-site.js` is
  about 407 KiB.

## Decisions taken without Ruben (unattended programme, 29 Sep 2026)

1. Only Nextcloud users who pass `PageEditorService::mayEdit()` (administrators
   and the editor groups) edit from the portal. Portal accounts (DigiD, OIDC
   visitors) never edit.
2. Portaliq composes the shared nextcloud-vue leaf pieces (`CnDashboardGrid`,
   the `dashboardWidgetRegistry` forms). No nextcloud-vue change: the library
   publishes only from main and beta, so a change there cannot reach Portaliq
   inside this change. buildiq's edit mode (`CnBuildiqEditButton` +
   `useManifestEditor` inside `CnAppRoot`) persists manifest deltas and is gated
   on buildiq, so it does not fit page objects with a draft and a publish.
3. Editing works on the Nextcloud origin (`/apps/portaliq/site/...`). On a custom
   portal domain there is no Nextcloud session, the editing context says
   `canEdit: false`, and nothing changes there.
4. The whole plan, phases 0 to 3, in order.

## D1. The editor core is plain JavaScript in `src/editor/`

`src/editor/` holds the editor as modules that import nothing from Vue, so
`node --test` runs them and both hosts share them:

| Module | What it owns |
|---|---|
| `gridModel.js` | Normalising stored placements, add (below everything), remove, merge a layout change by id, set a prop, a free placement id. Pure functions over an array. |
| `pageBody.js` | Reading a page into the editor (`grid` or `markdown`, draft first) and building the draft, publish and discard payloads so `body.type` and the markdown source survive. |
| `editHistory.js` | A capped undo and redo stack of snapshots, with coalescing for typing, and the keyboard intent (Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y). |
| `pageSaver.js` | Load a page and its version marker, save with the version check, name a conflict. The HTTP calls are injected. |
| `widgetForms.js` | Which shared `Cn*WidgetForm` configures a widget key, and the mapping between the form's `content` and the page's `props`. |
| `pageEditor.js` | The editor controller: state plus the actions above, over an injected `reactive()` so Vue sees it and a test does not need Vue. |
| `PageGridEditor.vue` | The canvas (`CnDashboardGrid`) and the inspector (shared form, else fields, else JSON), driven by a `pageEditor`. |
| `index.js` | The public API of the module; nothing outside `src/editor/` imports a file other than this one. |

`PageLayoutDesigner.vue` keeps its toolbar and its dialogs and delegates the
rest. The portal edit mode (A2) mounts the same `PageGridEditor.vue` over the
same controller.

Alternative considered: a Pinia store. Rejected: the site bundle has no Pinia,
and a store is a singleton where two editors (a page and, later, a region) need
two instances.

## D2. A markdown page is not a grid page

`readBody(page)` answers `{kind: 'markdown', markdown}` for a markdown body. The
designer shows "Deze pagina is markdown" with the source read-only and turns the
grid actions off. `publishPayload()` promotes the draft body as it is, whatever
its type, and `draftPayload()` for a markdown page writes a markdown draft. The
grid actions cannot reach the payload of a markdown page, so the destroying
write cannot happen. Converting a markdown page to a grid is not offered.

## D3. Shared forms first, fields second, JSON last

For a widget key the registry lists with a `form`, the inspector mounts that
form with `editingWidget: {type: key, content: props}` and takes its
`update:content` as the new `props`. That is the contract `CnAddWidgetModal`
uses, so the form behaves as it does in every dashboard. For a key with no
form, the fields introspected from the component's props stay
(`pageWidgetCatalogue.fieldsFor()`), and a key with neither is edited as one
JSON object. The public site blocks (`hero`, `section`, `cardGrid`, ...) have
no shared form in 2.57.3, so they keep their fields.

## D4. Undo and redo are snapshots of the widgets

Every change records the widgets and the selection before it happens. Typing in
one field coalesces into one step (same widget and prop), so Ctrl+Z undoes a
word-sized edit, not a letter. The stack holds 50 steps; a new change after an
undo drops the redo branch. A load starts a new history: undoing past a save
would show a state that is neither saved nor the draft. The keyboard shortcut
is ignored while focus is in a text field, so the browser's own undo keeps
working there.

## D5. The version check: re-read, then If-Match

The marker is the object's `@self.updated` (ISO 8601, as OpenRegister returns
it). A save:

1. re-reads the page; when its `updated` differs from the marker, the save is
   refused before any write, and the editor says who saved and when when the
   answer carries it;
2. sends the PUT with `If-Match: <marker>`. OpenRegister's
   `versionConflictResponse()` answers 409 when the object changed between the
   re-read and the write, which the editor reports the same way.

On a conflict the editor keeps the unsaved work on screen and offers a reload.
Nothing is overwritten silently.

## D6. Edit mode on the portal is a separate bundle (A2)

`SiteEditButton` offers "Deze pagina bewerken" when the editing context names
a page (and keeps "In de beheeromgeving openen" for the admin designer).
Choosing it loads `js/portaliq-site-editor.js` with one script tag
(`src/site/lib/loadSiteEditor.js`) and mounts it where the page was
(`src/editor/siteEditorMain.js`, root `SiteEditMode.vue`).

It is a bundle of its own rather than a dynamic `import()` chunk of the site,
and that was measured, not assumed: as a chunk, the editor shares Vue with the
entry, a module shared with a lazy chunk is no longer tree-shaken or
scope-hoisted there, and the ENTRY grew from 408.5 KiB to 428.6 KiB with none
of the editor in it, over the 410 KiB budget. As its own bundle with its own
Vue, the entry grows only by the loader and the button's action. The editor
bundle has no size budget: only an editor on the Nextcloud origin loads it.
Its chunks carry their own file prefix and its runtime its own global, so the
builds that share `js/` cannot collide.

The editing context already answers `pageId`; the version marker is read by
the editor when it loads the page, not from the probe, because the probe's
answer is older than the load and the check compares against what the editor
actually shows. The site is a standalone document with no Nextcloud CSS and
no Nextcloud translations, so the editor bundle registers the Dutch catalogue
(a chunk of its own) and gives the Nextcloud tokens the shared components read
portal-neutral values, using the portal's NL Design System tokens where one
exists. The palette is the public half of the catalogue. `WidgetGrid.vue` and
the editor compute a widget's cell with one function (`src/editor/geometry.js`),
and a test feeds both the same widgets.

## D7. Page tree and menu from the portal (A3)

`page` gains `parent` (a page uuid) and `order`; the register version bumps.
The route stays the address, so moving a page never breaks a link. From the
edit mode an editor creates a page (a draft under a parent), renames it, moves
it (parent and order), deletes a page whose status is `draft`, and edits the
portal's `menu` (add, rename, reorder, remove items). `applyToSchema()` writes
the editor groups into the `menu` schema's authorization as it does for `page`,
so OpenRegister refuses a menu write by anyone else.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Who may write pages and menus | Declarative, the schemas' authorization, written by the existing editor-group setting | One rule, enforced at the write. |
| The version check | OpenRegister's `If-Match` plus a client re-read | The store owns the guarantee; the re-read gives the message before the write. |
| The editor | Imperative, `src/editor/` | Interaction, not data. |
| The page tree | Declarative, `parent` and `order` on `page` | Data. |

## Risks

- **The site entry budget.** Anything the editor needs in the entry breaks the
  410 KiB limit. Mitigation: one dynamic import, measured in the PR.
- **A form that edits keys the page does not store.** The shared forms write
  `content`; the page stores `props`. The mapping is one object copy in
  `widgetForms.js`, tested both ways.
- **Two editors.** The version check refuses the second save; it does not
  merge. Merging is out of scope.
