# page-editor-in-the-page

## Why

The PtPagina board draws a page's layout editor as the "Indeling" tab of the page itself: the page's header, pills,
breadcrumb and tabs above it, a toolbar with "+ Blok toevoegen", undo, redo and Media, and every block as a card named
by what it is ("Hero", "Meldingsbalk", "Lijst met links") with its width under the name. Live, the editor was a page of
its own with its own title line, a row of text buttons, and blocks named by their code ("Nl Paragraph").

## What changes

- The page detail page gets an "Indeling" tab that holds the layout editor, through a registered `PageLayoutEditor`
  widget. Embedded, the editor drops its own title line, because the page's header already says it. The page puts its
  tab in the address (`?_tab=layout`), so the layout can be linked. The standalone `/pages/:id/layout` stays.
- The toolbar reads as the board: "+ Blok toevoegen" as the primary button with its icon, undo and redo as icon
  buttons, Media with its icon, and "Niet-opgeslagen wijzigingen" while there are unsaved changes.
- A block is named in the author's words for the NL Design System blocks too (Alinea, Kop, Lijst met links,
  Opzoekformulier, Meldingsbalk and the others), and its card says its width ("8 kolommen", "over de hele breedte").
- The example site seeds a draft for "Afval scheiden en ophalen" with the board's blocks (a hero, a notice, the
  lookup form and the link list), so the editor opens on what the board shows while the published page stays as it is.
