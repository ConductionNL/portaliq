---
status: proposed
---

# Spec: portal-in-place-editing

## Purpose

An editor edits a Portaliq portal from the portal itself: the page they are
reading, the pages around it and the menu, with the same editor the admin app
uses. Only Nextcloud users in the editor groups edit, and only on the Nextcloud
origin.

## ADDED Requirements

### Requirement: An editor MUST be able to edit a page in place on the portal (REQ-PIE-006)

On a page the editing context says the visitor may edit, the site SHALL offer
"Deze pagina bewerken". Choosing it SHALL replace the rendered page with the
editable grid in the portal's own theme, with a palette of public widgets only, a
configuration control per widget (edit, remove), save draft, publish, discard,
the page history, undo and redo, and a way to leave edit mode.

#### Scenario: An editor edits the page they are reading
- **GIVEN** an editor on the published page `/over-ons`
- **WHEN** the editor chooses "Deze pagina bewerken", adds a markdown widget and saves a draft
- **THEN** the page's `draftBody` holds the new widget and the public page is unchanged
- @e2e exclude proven by tests/site-edit-mode.spec.mjs; live-checked on :8080 by the coordinator

#### Scenario: The palette offers public widgets only
- **GIVEN** the portal edit mode
- **WHEN** the editor opens the palette
- **THEN** every entry offered is a widget the public renderer mounts
- @e2e exclude proven by tests/site-edit-mode.spec.mjs

#### Scenario: Leaving edit mode shows the page again
- **GIVEN** the portal edit mode with no unsaved change
- **WHEN** the editor leaves edit mode
- **THEN** the rendered page is shown as a visitor sees it
- @e2e exclude proven by tests/site-edit-mode.spec.mjs; live-checked on :8080 by the coordinator

### Requirement: The portal editor MUST NOT weigh on a visitor's first load (REQ-PIE-007)

The editor, the grid library and the widget forms SHALL load as a separate chunk
only when an editor enters edit mode. The site entry SHALL grow by no more than
the edit control and the import.

#### Scenario: The entry stays under budget
- **GIVEN** the production site build
- **WHEN** it is built
- **THEN** `portaliq-site.js` stays under the 410 KiB limit and the editor is in its own chunk
- @e2e exclude proven by the webpack.site.js budget and tests/site-edit-mode.spec.mjs

### Requirement: The editor and the public page MUST place widgets identically (REQ-PIE-008)

The public renderer and the editor SHALL compute a widget's column, row, width
and height through one function, so the same widgets land in the same cells in
both.

#### Scenario: Same widgets, same cells
- **GIVEN** widgets at `gridX` 0 and 6 with width 6, and one at `gridX` 10 with width 6
- **WHEN** the renderer's cell style and the editor's placement are computed
- **THEN** both put the first two side by side and clamp the third inside 12 columns
- @e2e exclude proven by tests/site-edit-mode.spec.mjs "renderer and editor agree on geometry"

### Requirement: Editing MUST be reserved to Nextcloud editors on the Nextcloud origin (REQ-PIE-009)

Only a Nextcloud user who passes `PageEditorService::mayEdit()` SHALL be offered
editing. A portal account SHALL never be. On a custom portal domain, where there
is no Nextcloud session, the editing context SHALL answer `canEdit: false`.

#### Scenario: A portal account is not an editor
- **GIVEN** a visitor signed in with DigiD
- **WHEN** the site asks the editing context
- **THEN** it answers `canEdit: false` and no edit control is shown
- @e2e exclude proven by tests/Unit/Controller/CmsEditorControllerTest.php

### Requirement: Pages MUST form a tree an editor manages from the portal (REQ-PIE-010)

A page SHALL carry an optional `parent` page and an `order`. From the portal edit
mode an editor SHALL create a page under a parent, rename it, move it to another
parent or position, and delete a page that was never published. The route SHALL
stay the page's address, so a move never changes a link.

#### Scenario: An editor creates a page under another
- **GIVEN** the portal edit mode on `/over-ons`
- **WHEN** the editor creates "Contact" under it with route `/over-ons/contact`
- **THEN** a draft page with that parent and route exists and is not served publicly
- @e2e exclude proven by tests/site-page-tree.spec.mjs; live-checked on :8080 by the coordinator

#### Scenario: A published page cannot be deleted from the portal
- **GIVEN** a published page
- **WHEN** the editor opens its actions
- **THEN** delete is not offered
- @e2e exclude proven by tests/site-page-tree.spec.mjs

### Requirement: An editor MUST be able to edit the portal's menu from the portal (REQ-PIE-011)

From the portal edit mode an editor SHALL add, rename, reorder and remove the
items of the portal's menu, and save them to the portal's `menu` object.

#### Scenario: An editor adds a menu item
- **GIVEN** the portal's menu with "Home" and "Over ons"
- **WHEN** the editor adds "Contact" linking to `/over-ons/contact` and saves
- **THEN** the menu object holds three items in that order
- @e2e exclude proven by tests/site-page-tree.spec.mjs; live-checked on :8080 by the coordinator

### Requirement: Writes to the menu MUST be governed by the editor groups (REQ-PIE-012)

Applying the editor groups SHALL write them into the `menu` schema's
authorization for create, update and delete, as it does for `page`, so
OpenRegister refuses a menu write by anyone else.

#### Scenario: The editor groups reach the menu schema
- **GIVEN** the editor groups `redactie`
- **WHEN** they are applied
- **THEN** the `menu` schema's create, update and delete rules name `redactie`, and its read rules are unchanged
- @e2e exclude proven by tests/Unit/Service/PageEditorServiceTest.php
