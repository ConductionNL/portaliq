## ADDED Requirements

### Requirement: A form offers help without losing the answers (REQ-HTF-001)

A form SHALL show "Hulp nodig?" when the portal or the form has help details. It SHALL open a dialog with the intro, Telefoon with its note, Openingstijden, Balie, "Vraag per e-mail" and Sluiten, each part only when set. A form's own help details SHALL override the portal's per key. Opening and closing the dialog MUST NOT change the answers entered.

#### Scenario: Help halfway the Woo request
- **WHEN** a resident has typed her request in step 1 and opens "Hulp nodig?"
- **THEN** the dialog shows the phone number, opening hours and the desk
- **AND** after Sluiten her text is still in the field

#### Scenario: A form with its own phone line
- **WHEN** the form sets `help.phone` and the portal sets another
- **THEN** the dialog shows the form's number and the portal's other details

#### Scenario: No help details
- **WHEN** neither portal nor form has help details
- **THEN** the form shows no "Hulp nodig?"

### Requirement: A page and each part of Mijn omgeving can carry a help text (REQ-HTF-002)

An editor SHALL be able to set a help text per CMS page and per part of Mijn omgeving (overzicht, zaken, taken, berichten, contacten). Where a text exists the page SHALL show a closed disclosure "Hulp bij deze pagina" under its heading; where none exists it SHALL show nothing.

#### Scenario: Help on Mijn taken
- **WHEN** the portal sets `sectionHelp.tasks` to "Hier staat wat de gemeente van u nodig heeft."
- **THEN** Mijn taken shows "Hulp bij deze pagina", which opens that text

#### Scenario: A page without help
- **WHEN** a page has no help text
- **THEN** no help control shows
