# portal-page-designer Specification

**Status**: implemented
**Scope**: portaliq
**OpenSpec changes**:

- [portal-page-designer](../../changes/archive/portal-page-designer/)

## Purpose

Direct-manipulation editing of a portal page's widget grid, reachable from the
site it renders. Extends `openspec/specs/portaliq-cms/spec.md`, which fixes the
grid geometry and the public allow-list this editor writes against. Related:
ADR-022 (writes through OpenRegister), ADR-084 (the public host), ADR-005
(fail-closed).

## Requirements

### Requirement: A page MUST carry a draft body that is never served publicly

A page SHALL be able to hold unpublished layout work in a `draftBody` separate
from the `body` the content API serves. Publishing SHALL promote the draft to
the body and clear the draft; discarding SHALL clear the draft and leave the
body untouched.

#### Scenario: A saved draft does not change the public page

- **GIVEN** a published page with a grid body
- **WHEN** an editor saves a changed layout as a draft
- **THEN** the public content API still returns the previous body, and the
  response carries no draft in any form

#### Scenario: Publishing promotes the draft

- **GIVEN** a page with a saved draft
- **WHEN** the editor publishes it
- **THEN** the public content API returns the draft's widgets as the page body,
  and the page no longer carries a draft

#### Scenario: Discarding leaves the published page intact

- **GIVEN** a page with a saved draft
- **WHEN** the editor discards it
- **THEN** the page keeps the body it had, and no draft remains

### Requirement: Who may edit pages MUST be configurable and enforced at the write

The groups whose members may create, update and delete portal pages SHALL be
configurable in Portaliq's admin settings. The configured groups SHALL be
written into the `page` schema's authorization block, so the check runs where
the write happens rather than only in the interface that offers it.

#### Scenario: A configured editor may write

- **GIVEN** a user in a configured editor group
- **WHEN** they save a page through OpenRegister's object API
- **THEN** the write succeeds

#### Scenario: An authenticated non-editor is refused

- **GIVEN** an authenticated user in no configured editor group and without
  admin rights
- **WHEN** they attempt to save a page through OpenRegister's object API
- **THEN** the write is refused

#### Scenario: Clearing the setting does not open the schema

- **GIVEN** editor groups are configured
- **WHEN** an administrator clears the setting
- **THEN** page writes are restricted to administrators, not opened to every
  authenticated user

#### Scenario: The read rules survive the write

- **GIVEN** the `page` schema's public read rule for published pages
- **WHEN** the editor groups are saved
- **THEN** anonymous visitors can still read published pages

### Requirement: A CMS read MUST NOT inherit another app's OpenRegister context

A read of this app's content SHALL address its own register and schema in a way
that cannot be captured by state another app left on the shared object service.

#### Scenario: A foreign pending schema does not break a content read

- **GIVEN** an earlier caller in the same request left a schema reference
  pending on the shared object service
- **WHEN** a portal's content is read
- **THEN** the read resolves this app's own schema and succeeds

#### Scenario: A schema owned by another app is never read

- **GIVEN** another app owns a schema with the same slug
- **WHEN** this app resolves that slug
- **THEN** it resolves the schema this app owns, or none at all

### Requirement: The site MUST offer an editing entry point only to a visitor who may edit

The rendered site SHALL show a floating editing control in the bottom-right
corner to a visitor whose session may edit pages, and SHALL show nothing to any
other visitor. The control SHALL open a menu whose actions reach the page
designer for the page being viewed, the page listing, and page creation.

#### Scenario: An anonymous visitor sees no control

- **GIVEN** a visitor with no session
- **WHEN** they open any page of the site
- **THEN** no editing control is present in the document

#### Scenario: An editor sees the control and reaches the designer

- **GIVEN** a signed-in visitor who may edit pages
- **WHEN** they open a page of the site and activate the editing control
- **THEN** a menu offers to edit that page, and choosing it opens the designer
  for the page at that route

#### Scenario: The identity of the page is not disclosed to others

- **GIVEN** a visitor who may not edit
- **WHEN** the editing context for a route is requested
- **THEN** the response states only that editing is unavailable, and names no
  page identifier

### Requirement: A page's widget grid MUST be editable by direct manipulation

The designer SHALL let an editor add a widget to a page, move it, resize it and
remove it on the shared 12-column grid, and SHALL persist the resulting
placements in the canonical widget-entry shape.

#### Scenario: A moved widget keeps its new cell

- **GIVEN** a page open in the designer
- **WHEN** the editor moves a widget to another cell and saves
- **THEN** the stored placement carries the new `gridX`/`gridY`, and reopening
  the designer shows it there

#### Scenario: A widget is added from the palette

- **GIVEN** a page open in the designer
- **WHEN** the editor adds a widget from the palette
- **THEN** the widget is placed on the grid with a valid geometry and its own
  identifier

#### Scenario: The grid may be edited without a pointer

- **GIVEN** a page open in the designer
- **WHEN** the editor moves a widget using the keyboard
- **THEN** the placement changes and the change is announced

### Requirement: The palette MUST mark widgets that cannot render on a public page

The palette SHALL offer the whole shared widget catalogue and SHALL mark every
entry that the public renderer will not mount, stating why, so an editor is not
offered a widget that would render as an inert placeholder without warning.

#### Scenario: A non-public widget is marked

- **GIVEN** a widget the public renderer does not mount
- **WHEN** the editor opens the palette
- **THEN** the entry is shown as unavailable for a public page, with the reason

#### Scenario: A public widget is offered normally

- **GIVEN** a widget the public renderer mounts
- **WHEN** the editor opens the palette
- **THEN** the entry is selectable

### Requirement: The designer MUST be reachable from the page administration surfaces

A declared designer route is not an entry point. Every surface on which an
administrator works with a page — the page's own detail page and the pages
overview — SHALL offer an action that opens the layout designer for that page,
and the designer SHALL offer the way back: a link to the page as the public
site serves it, naming the page's own portal so the link resolves on an
instance whose domain is not delegated.

#### Scenario: The page detail offers the designer

- **GIVEN** an administrator on a portal page's detail page
- **WHEN** they look at the page's actions
- **THEN** an action opens the layout designer for that same page

#### Scenario: The pages overview offers the designer per row

- **GIVEN** an administrator on the pages overview
- **WHEN** they open a row's actions
- **THEN** an action opens the layout designer for that row's page

#### Scenario: The designer links to the page on the public site

- **GIVEN** an editor in the layout designer for a page that belongs to a portal
- **WHEN** they follow the link to the site
- **THEN** the URL carries the page's route and that portal's slug

### Requirement: The designer MUST keep a markdown page's markdown (REQ-PIE-001)

The designer SHALL open a page whose body type is `markdown` in a markdown state
that shows the source and offers no grid actions. A draft save, a publish or a
discard from the designer SHALL keep the page's `body.type` and its markdown
source.

#### Scenario: Publishing a markdown page keeps its markdown
- **GIVEN** a page whose `body` is `{type: 'markdown', markdown: '## Over ons'}`
- **WHEN** an editor opens it in the designer and publishes
- **THEN** the stored `body` is still `{type: 'markdown', markdown: '## Over ons'}`
- @e2e exclude proven by tests/page-editor.spec.mjs "publishing a markdown page keeps its markdown"

#### Scenario: A markdown page opens as markdown
- **GIVEN** the same page
- **WHEN** the designer loads it
- **THEN** the editor state is `markdown`, it holds the source, and adding a widget changes nothing
- @e2e exclude proven by tests/page-editor.spec.mjs "a markdown page opens as markdown and refuses grid edits"

### Requirement: The admin designer and the portal edit mode MUST share one editor core (REQ-PIE-002)

The grid model, adding, removing and moving widgets, prop edits, the draft,
publish and discard payloads and the save SHALL live in `src/editor/` and be used
by the admin designer and by the portal edit mode alike. Neither host SHALL keep
its own copy.

#### Scenario: The designer delegates to the editor core
- **GIVEN** `src/views/PageLayoutDesigner.vue`
- **WHEN** its source is read
- **THEN** it creates its editor through `src/editor/index.js` and builds no body payload of its own
- @e2e exclude proven by tests/page-editor.spec.mjs "the designer delegates to the editor core"

#### Scenario: A widget added from the palette lands below the page
- **GIVEN** a grid page with a widget at rows 0 to 3
- **WHEN** an editor adds a `markdown` widget
- **THEN** it is placed at row 4, column 0, with the catalogue's default size, and selected
- @e2e exclude proven by tests/page-editor.spec.mjs "an added widget lands below everything and is selected"

### Requirement: A widget with a shared configuration form MUST be configured through it (REQ-PIE-003)

When the shared widget registry lists a `form` for a widget's key, the inspector
SHALL configure the widget through that form, reading the widget's `props` as the
form's content and storing the form's content as the widget's `props`. A key
without a form SHALL keep the field list, and a key without fields SHALL be
edited as one JSON object.

#### Scenario: A text widget uses the shared text form
- **GIVEN** a placed widget with key `text` and props `{text: 'Welkom'}`
- **WHEN** the editor selects it
- **THEN** the inspector mounts the registry's form for `text` with content `{text: 'Welkom'}`, and a change from the form is stored in `props`
- @e2e exclude proven by tests/page-editor.spec.mjs "a key with a shared form is configured through it"

#### Scenario: A public block without a form keeps its fields
- **GIVEN** a placed `hero` widget
- **WHEN** the editor selects it
- **THEN** no shared form is used and the fields read from the component's props are offered
- @e2e exclude proven by tests/page-editor.spec.mjs "a key without a form falls back to fields"

### Requirement: Editor changes MUST be undoable and redoable (REQ-PIE-004)

The editor SHALL undo its last change with Ctrl+Z (Cmd+Z) and redo it with
Ctrl+Shift+Z or Ctrl+Y, and offer both as buttons. The history SHALL hold at most
50 steps, typing in one field SHALL count as one step, and a new change after an
undo SHALL drop what could be redone. The shortcut SHALL be left to the browser
while focus is in a text field.

#### Scenario: Undo and redo a removal
- **GIVEN** a page with two widgets
- **WHEN** the editor removes one, presses Ctrl+Z, then Ctrl+Shift+Z
- **THEN** the widget is back after the undo and gone again after the redo
- @e2e exclude proven by tests/page-editor.spec.mjs "undo and redo a removal"

#### Scenario: The history is capped
- **GIVEN** 60 changes in a row
- **WHEN** the editor undoes as far as it can
- **THEN** it undoes 50 of them
- @e2e exclude proven by tests/page-editor.spec.mjs "the history holds 50 steps"

### Requirement: A save MUST NOT overwrite a newer save by someone else (REQ-PIE-005)

The editor SHALL remember the page's `updated` marker when it loads the page.
Before a write it SHALL re-read the page and refuse the write when the marker
changed, and it SHALL send the marker as `If-Match` so OpenRegister refuses a
write that races the re-read. A refused save SHALL say that someone else saved
the page, keep the unsaved work on screen, and offer to reload.

#### Scenario: Someone else saved in between
- **GIVEN** an editor who loaded a page at `updated` 10:00
- **WHEN** another editor saves it at 10:05 and the first editor then saves a draft
- **THEN** no write is sent, the editor sees that the page was changed by someone else, and the unsaved widgets stay
- @e2e exclude proven by tests/page-editor.spec.mjs "a save after someone else saved is refused before the write"

#### Scenario: The store refuses a racing write
- **GIVEN** a re-read that still shows 10:00
- **WHEN** the PUT with `If-Match: 10:00` is answered 409
- **THEN** the editor reports the same conflict
- @e2e exclude proven by tests/page-editor.spec.mjs "a 409 from the store is a conflict"

### Requirement: An editor MUST be able to shape a text block without knowing markdown

The designer SHALL show the text of a "Tekst" block (`markdown`) in a field labelled "Tekst", with a toolbar (`role="toolbar"`, with an accessible name) above the textarea. The toolbar SHALL offer Kop, Vet, Cursief, Lijst and Link as real buttons, each with an accessible name. Each button SHALL write the markdown for its mark into the textarea at the selection: `## ` at the start of the line for Kop, `**text**` for Vet, `_text_` for Cursief, `- ` before every selected line for Lijst, and `[text](address)` for Link. Ctrl+B and Ctrl+I, or Cmd on a Mac, SHALL do what Vet and Cursief do. The toolbar SHALL be one Tab stop with a roving tabindex: Left and Right SHALL move the focus between the buttons and wrap around, and Home and End SHALL go to the first and the last button. Link SHALL ask for the address in the panel, not in a browser prompt. After a button the focus SHALL return to the textarea with the selection on the changed text. The block SHALL keep storing markdown in `props.markdown` (REQ-ETT-001).

#### Scenario: A heading without typing markdown

- **GIVEN** a "Tekst" block selected in the designer, with the line "Fietspad Lindelaan" in its text
- **WHEN** the editor puts the cursor on that line and chooses Kop
- **THEN** the stored markdown holds `## Fietspad Lindelaan` and the cursor is still in the text
- @e2e exclude covered by `tests/editor-text-toolbar.spec.mjs` (the transformation and the wiring); the coordinator's live film run drives the button

#### Scenario: Bold and italic from the keyboard

- **GIVEN** a word selected in the text of a "Tekst" block
- **WHEN** the editor presses Ctrl+B, or Ctrl+I
- **THEN** the word is wrapped in `**`, or in `_`, and stays selected
- @e2e exclude covered by `tests/editor-text-toolbar.spec.mjs` (`shortcutFor` and the wrap)

#### Scenario: The toolbar is one Tab stop and the arrows move along it

- **GIVEN** the focus on the Kop button
- **WHEN** the editor presses Left
- **THEN** the focus moves to Link, the last button, which becomes the toolbar's only Tab stop
- @e2e exclude covered by `tests/editor-text-toolbar.spec.mjs` (`toolbarIndexFor`, `rovingTabindexes` and the wiring)

#### Scenario: A list from several lines

- **GIVEN** three lines selected, one of them empty
- **WHEN** the editor chooses Lijst
- **THEN** the two lines with text start with `- ` and the empty line stays empty
- @e2e exclude covered by `tests/editor-text-toolbar.spec.mjs`

#### Scenario: A link asks for its address in the panel

- **GIVEN** some words selected in the text
- **WHEN** the editor chooses Link, types an address and confirms
- **THEN** the words become `[words](address)` and no browser prompt was shown
- @e2e exclude covered by `tests/editor-text-toolbar.spec.mjs` (`applyLink`, and the component has no `window.prompt`)

#### Scenario: Existing pages keep working

- **GIVEN** a page whose "Tekst" block was written before the toolbar existed
- **WHEN** it is opened in the designer and on the site
- **THEN** the same markdown is shown in the textarea and rendered on the page, because the stored prop did not change
- @e2e exclude the stored shape is unchanged (`props.markdown`); asserted by the existing page-editor tests over the real `page` schema
