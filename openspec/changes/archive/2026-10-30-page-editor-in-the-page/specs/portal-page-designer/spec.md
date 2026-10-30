# portal-page-designer Delta: page-editor-in-the-page

## ADDED Requirements

### Requirement: The layout editor is a tab of the page

The page detail page SHALL carry an "Indeling" tab holding the layout editor (`PageLayoutEditor` widget). Embedded, the
editor SHALL NOT draw its own title, route and status line. The tab SHALL be addressable as `?_tab=layout`. The
standalone layout page SHALL keep working.

#### Scenario: PtPagina

- **GIVEN** the page "Afval scheiden en ophalen"
- **WHEN** an editor opens its Indeling tab
- **THEN** the page header, pills and tabs stand above the editor and the editor shows no second title

### Requirement: The editor toolbar and block cards read as the board

The toolbar SHALL put "Blok toevoegen" first as the primary button with a plus icon, undo and redo as icon buttons with
their names as accessible labels, and Media with its icon, and SHALL say "Niet-opgeslagen wijzigingen" while the page
has unsaved changes. A block card SHALL name the block in the author's words for every NL Design System block in use,
never its code, and SHALL say its width under the name: "{n} kolommen", or "over de hele breedte" at 12.

#### Scenario: a lookup form 8 columns wide

- **GIVEN** an `nlLookupForm` block 8 columns wide
- **THEN** its card reads "Opzoekformulier" over "8 kolommen"

### Requirement: The example site seeds the board's draft

The example site SHALL seed a draft body for "Afval scheiden en ophalen" holding a hero, a warning notice, the lookup
form and the link list, leaving its published body as it is.

#### Scenario: a fresh example site

- **GIVEN** the Zuiddrecht example site installed on an empty instance
- **THEN** the page's editor opens on the draft with those four blocks, and the public page shows the published body
