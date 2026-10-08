## ADDED Requirements

### Requirement: The editor names a block by its widget's name

The page editor MUST name a placed block by its widget's name, the same name the palette shows: the public block's label ("Tekst (markdown)"), else the name the shared dashboard registry gives it, else its key written as words. This holds for the block's bar on the page, its accessible name and the inspector. The raw key ("markdown") MUST NOT be shown where a name exists.

#### Scenario: A text block on the page
- GIVEN an editor places a `markdown` block
- WHEN the page is in edit mode
- THEN the block's bar and the inspector read "Tekst (markdown)"
- @e2e exclude pinned by `tests/page-editor.spec.mjs` ("a widget reads by its name", "the editor shows the widget name, never the raw key")

### Requirement: The editor's notices must read at AA contrast

The editor's success, error, warning and info notices, and the buttons drawn in those colours, MUST reach a WCAG AA contrast of at least 4.5:1 for their text, using CSS variables only. Each status colour is a light tint mixed from its `-text` colour and the page background.

#### Scenario: "Gepubliceerd." after publishing
- GIVEN an editor publishes a page
- WHEN the "Gepubliceerd." notice shows
- THEN its text and its edge reach at least 4.5:1 against its background
- @e2e exclude pinned by `tests/site-edit-mode.spec.mjs` ("the editor notices and the delete button read at AA contrast, from tokens only")
