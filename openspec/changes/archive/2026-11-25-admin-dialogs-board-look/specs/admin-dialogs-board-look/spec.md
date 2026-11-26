# admin-dialogs-board-look Delta: admin-dialogs-board-look

## ADDED Requirements

### Requirement: An admin dialog is the library dialog

Every dialog in `src/dialogs` SHALL render nextcloud-vue's `CnDialog` and SHALL accept a `look` prop that it passes on. Under `look: "board"` it is the board dialog; under the Nextcloud look it is Nextcloud's dialog, unchanged.

#### Scenario: The invite dialog is drawn as the board draws it
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtUitnodigen
- **GIVEN** the portaliq manifest declares `look: "board"`
- **WHEN** staff open "Iemand uitnodigen" on the invitations page
- **THEN** the dialog is 640px wide with the board header and its close button

#### Scenario: The media picker is the wide dialog
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtMediaKiezen
- **WHEN** an editor opens Media in the page layout designer
- **THEN** the dialog takes the `wizard` width, 720px

### Requirement: A spawned dialog takes the app's look

A dialog opened with `spawnDialog` SHALL be opened through `spawnLookDialog`, which passes the manifest's look as the dialog's `look` prop. An explicit `look` passed by the caller SHALL win.

#### Scenario: Issuing an account opens the board dialog
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtAccountUitgeven
- **GIVEN** the portaliq manifest declares `look: "board"`
- **WHEN** staff press "Account uitgeven"
- **THEN** the dialog opens with `look` set to `board`

### Requirement: An admin dialog names where it was opened

In the board look a dialog SHALL carry the boards' context line above its title: the page it was opened from ("Uitnodigingen", "Accounts"), and for the media picker the page the media is chosen for ("Afval scheiden en ophalen, media kiezen").

#### Scenario: The invite dialog names the invitations page
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtUitnodigen
- **WHEN** staff open "Iemand uitnodigen"
- **THEN** "Uitnodigingen" stands above the title

#### Scenario: The media picker names the page
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtMediaKiezen
- **GIVEN** the page "Afval scheiden en ophalen" is open in the layout designer
- **WHEN** the editor opens Media
- **THEN** "Afval scheiden en ophalen, media kiezen" stands above the title

### Requirement: The media picker draws the library as cards

The media picker SHALL draw the portal's published library items as a grid of cards, three to a row where the dialog is wide enough. Each card SHALL show a thumbnail for an image (from the public media route of the page's portal, with the item's alt text as its alternative text) or a file mark for a file, the name of the file the item serves, and the item's alt text. Pressing a card SHALL select it, and only one card SHALL be selected at a time. The uses of the selected item (hero image and share image for an image, "Copy for a text" for any item) SHALL stand in one action bar under the grid, and copying SHALL be confirmed in a status line.

#### Scenario: The editor picks a hero image from the cards
@e2e exclude covered by tests/media-library.spec.mjs and tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtMediaKiezen
- **GIVEN** the page's portal has two published images and one published file
- **WHEN** the editor opens Media and presses the card of the first image
- **THEN** three cards show, the first selected, each with its file name and alt text
- **AND** the action bar offers "Use as hero image", "Use as share image" and "Copy for a text" for the selected image

#### Scenario: A file offers only the text reference
@e2e exclude covered by tests/admin-dialogs-board-look.spec.mjs; measured by COMPDIFF PtMediaKiezen
- **WHEN** the editor selects the card of a file
- **THEN** the action bar offers only "Copy for a text"
- **AND** after copying, "Copied. Paste it into a text widget." is announced
