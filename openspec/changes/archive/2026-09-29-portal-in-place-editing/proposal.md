---
kind: code
depends_on: []
---

# Proposal: portal-in-place-editing

## Why

An editor who reads a portal page and spots a mistake has to leave the portal,
open the Portaliq admin app, find the page, open its layout designer, fix it
there and go back to the portal to look. Every CMS the portal is compared with
(WordPress, Drupal, Liferay, and OpenBuild inside our own fleet) edits the page
where it is read. Ruben's goal for this change: edit a Portaliq portal from the
portal itself, at the level of OpenBuild's in-app editing.

The designer an editor would reach today also has a defect that gets worse the
more it is used: `PageLayoutDesigner.vue` loads only `body.widgets` and always
publishes `body: {type: 'grid', widgets}`, so publishing a markdown page from the
designer destroys its markdown. It has no undo, and two editors who save the same
page overwrite each other without a word.

## What changes

- **The designer keeps a markdown page's markdown.** A markdown page opens in a
  clear "this page is markdown" state, the grid actions are off, and a draft
  save, a publish or a discard keeps `body.type` and the markdown source.
- **One editor core, two hosts.** The grid model, add, remove, move, prop edits,
  draft, publish, discard and the save move out of the admin view into
  `src/editor/`. The admin designer uses it now; the portal edit mode uses the
  same core.
- **Shared widget forms.** A widget whose key has a configuration form in the
  shared nextcloud-vue registry (`Cn*WidgetForm`) is configured through that form.
  The field list introspected from the widget's props stays as the fallback, and
  the raw JSON field only for props no form or field covers.
- **Undo and redo.** Ctrl+Z and Ctrl+Shift+Z (and the toolbar buttons) step
  through the editor's own changes, with a capped history.
- **No lost update.** The editor remembers the page's `updated` marker at load.
  A save re-reads the page first and refuses when someone else saved in between,
  and sends `If-Match` so OpenRegister refuses a write that races the re-read.
- **Edit mode on the portal.** "Deze pagina bewerken" in the site's edit control
  loads a lazily imported editor chunk that swaps the rendered page for the
  editable grid, in place and in the portal's theme. The palette offers public
  widgets only. Save draft, publish, discard, history and leaving edit mode are
  on the page.
- **The rest of the portal from the portal.** Pages form a tree (`parent`), and
  from the portal an editor creates a page, renames it, moves it, deletes a page
  that was never published, and edits the portal's menu. Writes to `menu` are
  governed by the same editor groups as `page`.

## Existing work it builds on

- `portal-page-designer` (spec): the designer, draft and publish, the editor
  groups (`PageEditorService`), the site's editing entry point.
- `site-page-seo-history-and-media` (spec): the History dialog and restore
  (`PageHistoryDialog.vue`, `pageHistory.js`), reused, not rebuilt.
- OpenRegister's optimistic concurrency on PUT (`If-Match` on the object's
  `updated`, `ObjectsController::versionConflictResponse()`).
- nextcloud-vue 2.57.3: `CnDashboardGrid` and the `dashboardWidgetRegistry`
  with its `form` entries.

## Out of scope

- The contribution `portalPage` (the React `/portal` block list).
- Editing on a custom portal domain: there is no Nextcloud session there, the
  editing context answers `canEdit: false` and nothing changes.
- Portal accounts (DigiD, eHerkenning, OIDC visitors) editing anything.
- Any change to nextcloud-vue. It publishes from main and beta only, so a
  library change cannot reach Portaliq inside this change; Portaliq composes the
  exported leaf pieces.
- Header, footer and hero regions: change `portal-theme-blocks-and-contributed-pages`
  tasks 4 to 8, which build on the editor core this change introduces.

## Delivery

Four stacked pull requests: A1 (this change's artifacts, the markdown fix, the
editor core, shared forms, undo and redo, the version check), A2 (edit mode on
the portal), A3 (page tree, page and menu management from the portal), and the
archive.
