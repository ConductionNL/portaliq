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
