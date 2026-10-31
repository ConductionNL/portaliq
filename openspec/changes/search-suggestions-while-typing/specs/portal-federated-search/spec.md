## ADDED Requirements

### Requirement: The search box suggests publications while you type (REQ-SST-001)

After at least three typed characters and 250 ms without typing, the search block and the header search box SHALL show at most five publication titles from the same federation search, each with its kind. The list SHALL show nothing when the request fails or takes longer than one second, and searching SHALL keep working. A suggestion SHALL open that publication.

#### Scenario: Three suggestions
- **WHEN** a visitor types "fietspad Lin" and pauses
- **THEN** the list shows matching titles such as "fietspad Lindelaan verlichting" with the kind "Woo-besluit"

#### Scenario: Two characters
- **WHEN** a visitor types "fi"
- **THEN** no suggestion list opens

#### Scenario: The search source is down
- **WHEN** the suggestion request fails
- **THEN** no list and no error show, and pressing Zoeken still runs the search

### Requirement: The suggestion list works by keyboard and screen reader (REQ-SST-002)

The search input SHALL be a combobox controlling a listbox. Arrow keys SHALL move the active suggestion, Enter SHALL open it, Escape SHALL close the list and keep the text. The live region SHALL announce the number of suggestions when the list opens.

#### Scenario: Keyboard only
- **WHEN** a keyboard user types "fietspad", presses arrow down twice and Enter
- **THEN** the second suggestion's publication opens

#### Scenario: Screen reader
- **WHEN** the list opens with three suggestions
- **THEN** the live region announces "3 suggesties"
