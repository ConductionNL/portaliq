# Proposal: editor-text-toolbar

## Why

Found filming the Woo journey on 2026-10-04. Selecting a "Tekst" block in the page editor showed a side panel field labelled "Markdown" with a bare textarea. To write a heading the editor had to type `## Fietspad Lindelaan`. An editor at a municipality does not know markdown, and should not need to.

## What changes

- The field is labelled "Tekst" instead of "Markdown".
- A toolbar sits above the textarea with five buttons: Kop, Vet, Cursief, Lijst and Link. Each one writes the markdown for it around or in front of the selection. Ctrl+B and Ctrl+I (Cmd on a Mac) do the same as Vet and Cursief.
- Link asks for the web address in a small form under the toolbar, not in a browser prompt.
- After a button the cursor is back in the textarea with the selection kept, so the editor carries on typing.
- The block still stores markdown in `props.markdown`, so every existing page keeps rendering, and the textarea keeps `data-testid="designer-field-markdown"`.

## Out of scope

- The whole-page markdown view (a page whose body is markdown, not a grid). It is read-only in the designer and stays so.
- A preview of the formatted text in the side panel. The block on the canvas already shows it.
