## ADDED Requirements

### Requirement: The palette MUST group widgets and let an editor search them (REQ-SNW-001)

The palette MUST show its public widgets in six groups, each under a heading, in this order: Inhoud, Navigatie, Formulieren, Terugkoppeling, Mijn omgeving, Opmaak. Widgets that do not render on a public page MUST come last, in the admin designer only, and stay marked as today. A search field MUST filter the palette on a widget's label, key, NL Design System name and synonyms, and MUST announce the number of hits. Every public widget MUST have a Dutch label.

#### Scenario: An editor looks for a heading
- GIVEN the palette is open
- WHEN the editor types "kop"
- THEN the Heading widget is listed under Inhoud
- AND a screen reader hears how many widgets match

#### Scenario: No widget is named by its key alone
- GIVEN the public widgets on `development`
- WHEN the palette renders
- THEN `siteNavigation` reads "Menu" and `form` reads "Formulier", not "Site navigation" and "Form"

### Requirement: An editor MUST be able to drag a widget from the palette onto the grid (REQ-SNW-002)

An editor MUST be able to drag a palette entry onto a grid cell, which places the widget there at its default size. Choosing an entry with a click or the Enter key MUST place it below everything already on the page, so the author sees where it went. The placed widget MUST get its own identifier and a valid geometry.

A SURPRISING PLACEMENT IS WORSE THAN A PREDICTABLE ONE. This requirement said "at the first free cell" until the editor was built, and the code appends below everything instead, for the reason `gridModel.addWidget()` carries in its docblock: a widget squeezed into a gap somewhere in the middle looks to the author like nothing happened. On an empty page the two readings agree, which is why the difference went unnoticed while it was written down. The requirement now says what the editor does and what an author can rely on; the drop is the gesture that places a widget somewhere in particular.

#### Scenario: A heading dropped at the top
- GIVEN a page open in the designer
- WHEN the editor drags "Kop" to the first row
- THEN a heading widget sits in the first row with its default size

#### Scenario: Keyboard only
- GIVEN the palette is open and focus is on "Kop"
- WHEN the editor presses Enter
- THEN a heading widget is placed below everything already on the page
- AND the editor can see it without scrolling past other widgets to look for it

#### Scenario: Both ways in place the same widget
- GIVEN a page with one widget in the first row
- WHEN the editor drops a heading on the second row, and on another page places one with Enter
- THEN both placements have the same geometry, their own identifiers and no properties set

### Requirement: Form fields MUST be added inside a form widget (REQ-SNW-003)

The Formulieren group MUST add a field to the selected `form` widget, not a widget to the grid. With no form widget selected, the group MUST say that a form must be chosen first. Each field type MUST offer its own editable fields, and always its name, label and whether it is required. The field list MUST be stored on the form widget.

#### Scenario: A date field in a contact form
- GIVEN a `form` widget is selected
- WHEN the editor adds "Datum" from Formulieren and labels it "Geboortedatum"
- THEN the form shows a day, month and year group with the legend "Geboortedatum"

#### Scenario: No form selected
- GIVEN no `form` widget is selected
- WHEN the editor opens Formulieren
- THEN the group says to select a form first and adds nothing
