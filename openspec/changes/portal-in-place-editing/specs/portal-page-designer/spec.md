---
status: proposed
---

# Spec: portal-page-designer (delta)

## Purpose

The page designer keeps every page it opens intact, shares one editor core with
the portal edit mode, configures widgets through the shared forms, undoes and
redoes, and never overwrites a newer save.

## ADDED Requirements

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
