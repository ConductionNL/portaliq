# Proposal: admin-dialogs-board-look

## Why

COMPDIFF pq1 (20 October): the dialogs on PtAccountUitgeven, PtUitnodigen and PtMediaKiezen are Nextcloud's own dialog on live: 600px wide, radius 8, no shadow, a 30px centred title and no close button in the header. The boards draw the library's board dialog: 640px (720px for the media picker), radius 12, the shadow, a 20px title at the left with a round close button. Portaliq rendered `NcDialog` directly, so the board look never reached its dialogs.

## What changes

- Every dialog in `src/dialogs` renders `CnDialog` (nextcloud-vue 2.91 and later) and takes a `look` prop.
- A dialog opened with `spawnDialog` is mounted outside CnAppRoot, so it gets the manifest's look through `spawnLookDialog` (`src/lib/dialogLook.js`).
- The media picker takes the 720px `wizard` width; the others keep the 640px `form` width.
- In the Nextcloud look nothing changes: `CnDialog` is `NcDialog` with the caller's size.

## Depends on

`chore/bump-nextcloud-vue-2.115` (nextcloud-vue ^2.115.0), which this branch is based on.

## Rollback

Revert the commit; the dialogs are `NcDialog` again.
