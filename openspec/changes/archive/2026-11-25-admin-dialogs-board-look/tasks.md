# Tasks: admin-dialogs-board-look

- [x] 1.1 The dialogs render CnDialog with a look prop
  - Files: src/dialogs/*.vue
  - Test: tests/admin-dialogs-board-look.spec.mjs
- [x] 1.2 A spawned dialog takes the manifest's look
  - Files: src/lib/dialogLook.js, src/customComponents.js, src/widgets/PortalRegistration.vue, src/widgets/PortalAccountWithdraw.vue
  - Test: tests/admin-dialogs-board-look.spec.mjs
- [x] 1.3 The invite, issue and media dialogs carry the boards' context line
  - Files: src/dialogs/InviteDialog.vue, src/dialogs/IssueAccountDialog.vue, src/dialogs/MediaPickerDialog.vue, src/views/PageLayoutDesigner.vue, l10n/*
  - Test: tests/admin-dialogs-board-look.spec.mjs
- [x] 1.4 Live: COMPDIFF PtAccountUitgeven, PtUitnodigen and PtMediaKiezen after a build
  - Done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main and portaliq development, board beside live at 1440 wide. PtAccountUitgeven: "Een account uitgeven" with the context line Accounts, Naam and E-mailadres side by side, the checked-address toggle with its hint, Identiteitstype and Identiteitsreferentie with their hints, Organisatie and Doelgroep, the note on a second account, Annuleren and "+ Account uitgeven": as drawn. PtUitnodigen: "Iemand uitnodigen" with the context line Uitnodigingen, E-mailadres, "Organisatie (niet verplicht)", Doelgroep, the 14-days note, Annuleren and "Uitnodiging versturen": as drawn (the board repeats the context line once more under the eyebrow). PtMediaKiezen (page designer, Media): the context line "<page>, media kiezen" and the title Media are there, but the body is not as drawn: the board shows the library as a three-column grid of cards with a thumbnail, file name and alt text, a selected card, an action bar for the selected item (Gebruiken als hoofdafbeelding, Gebruiken als deelafbeelding, Kopiëren voor een tekst) and a copy confirmation; live lists each item's title with its three buttons and no image. See 1.5.
  - Fixed here: `tests/admin-dialogs-board-look.spec.mjs` "every dialog renders CnDialog and passes its look prop on" was red on development because the later `MailTemplateDialog.vue` imported CnDialog together with CnMarkdownEditor and wrote its `look` prop on one line; it now follows the dialogs' convention (5/5).
- [x] 1.5 The media picker draws the library as PtMediaKiezen does: a three-column card grid with thumbnail, file name and alt text, one selected card, the three uses in an action bar for the selected item, and the copy confirmation. Found by the 1.4 live check.
  - Files: src/dialogs/MediaPickerDialog.vue, src/lib/mediaLibrary.js, l10n/*
  - Test: tests/media-library.spec.mjs, tests/admin-dialogs-board-look.spec.mjs
  - The thumbnail is the item's public media route on the page's portal; the file name is the newest file's name from OpenRegister's files list, the same file that route streams. A COMPDIFF against PtMediaKiezen after the next build stays with the live lane.
