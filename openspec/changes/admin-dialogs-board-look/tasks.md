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
- [ ] 1.4 Live: COMPDIFF PtAccountUitgeven, PtUitnodigen and PtMediaKiezen after a build
