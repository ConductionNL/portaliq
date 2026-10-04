## What changes

A selected "Tekst" block showed a bare textarea labelled "Markdown" in the side panel, so a heading meant typing `## Fietspad Lindelaan`. Now:

- The field reads **Tekst**.
- A toolbar sits above the textarea (`role="toolbar"`, named "Tekstopmaak") with five real buttons: **Kop**, **Vet**, **Cursief**, **Lijst** and **Link**.
  - Kop puts `## ` at the start of the line (again removes it; `#` or `###` becomes `##`).
  - Vet wraps the selection in `**`, Cursief in `_` (again removes them). Edge whitespace stays outside the marks. With nothing selected the marks go in with the cursor between them.
  - Lijst puts `- ` before every selected line with text and leaves empty lines empty; on a list it removes them.
  - Link opens a small form in the panel asking for the web address (Enter inserts, Escape cancels), no `window.prompt`. It writes `[text](address)`; with nothing selected the address is the text.
- Ctrl+B and Ctrl+I (Cmd on a Mac) do what Vet and Cursief do.
- The toolbar follows the WAI-ARIA toolbar pattern: one Tab stop with a roving tabindex (the last used or focused button, the first to start with), Left and Right move between the buttons and wrap around, Home and End go to the first and last.
- After a button the focus is back in the textarea with the selection on the changed text.
- The block still stores markdown in `props.markdown`, so existing pages keep working. The textarea keeps `data-testid="designer-field-markdown"`. New test ids: `designer-text-toolbar`, `designer-text-{heading,bold,italic,list,link}`, `designer-text-link-form`, `designer-text-link-url`, `designer-text-link-insert`, `designer-text-link-cancel`.
- Colours come from the existing editor variables (`--color-border`, `--color-main-background`, `--color-main-text`, `--color-background-hover`, `--color-primary-element`), with a visible focus ring.

## Files

- `src/editor/markdownToolbar.js` (new): the pure transformations, selection in, text and selection out.
- `src/editor/MarkdownField.vue` (new): the field with its toolbar and link form.
- `src/editor/PageGridEditor.vue`: renders `MarkdownField` for a field of kind `markdown`.
- `src/lib/pageWidgetCatalogue.js`: the `markdown` field override gets kind `markdown`.
- `l10n/{nl,en}.json` and the regenerated `.js`: nine new strings (Kop, Vet, Cursief, Lijst, Webadres, Link invoegen, ...).
- `tests/editor-text-toolbar.spec.mjs` (new), `package.json` (`check:editor-text-toolbar`, chained into `check:specs` so CI runs it).
- `openspec/changes/editor-text-toolbar/`: proposal, tasks and the ADDED requirement.

## Relation to #1162

#1162 changes the canvas (drag and drop) and the palette, not the inspector field, so there is no overlap in behaviour. #1162 has landed; this branch merged `development` and `check:specs` keeps #1162's checks plus `check:editor-text-toolbar`.

## Verified locally (once, on this head)

- `npm run lint`: exit 0
- `npm run format` (prettier check): exit 0
- `npm run check:l10n-js`: exit 0
- `npm run check:schema-l10n`: exit 0
- `npm run check:specs`: exit 0 (now includes `check:editor-text-toolbar`, 27 tests: every button, empty selection, multi-line list, toggling off, shortcuts, roving tabindex and arrow keys, wiring, Dutch strings)
- `npm run check:page-editor`: exit 0
- `npm run check:reuse`: exit 0 (on the first commit)

Inherited red: after merging `development` (which now holds #1162 and #1166), `check:news-item-translation` fails inside `check:specs` on `development` itself: #1166 moved `newsItem` to 0.3.0 and `tests/news-item-translation.spec.mjs` still allows only 0.2.0 or 0.2.1. This PR does not touch the register. Every other `check:specs` step (103 of 104, including `check:editor-text-toolbar`) passes when run on its own.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
