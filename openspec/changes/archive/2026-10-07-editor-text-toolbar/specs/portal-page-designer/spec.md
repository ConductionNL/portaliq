## ADDED Requirements

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
