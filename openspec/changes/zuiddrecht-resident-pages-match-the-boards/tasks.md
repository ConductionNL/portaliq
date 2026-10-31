# Tasks: The Zuiddrecht resident pages match the boards

## 1. Spec
- [x] 1.1 Proposal and the two spec deltas.

## 2. Server
- [x] 2.1 `lib/Contribution/BoardKeys.php`: the board keys, each kept only when well formed; wired into the list, school, record and resolver normalisers.
- [x] 2.2 Portal schema: `residentMenu.groups` and `myCases.display`; register 0.67.0, portal 0.12.0.
- [x] 2.3 The Zuiddrecht example site declares its menu groups and the rows display.

## 3. Client
- [x] 3.1 CaseCard compact and row displays, the info tone, the number and due day on the card data.
- [x] 3.2 CasesBlock "Alle zaken" beside the heading; TasksBlock highlight tone and due line; InboxBlock plain list.
- [x] 3.3 DocumentsBlock upload; DetailCard heading and timeline switch; CitizenCase actions display.
- [x] 3.4 ContributionPage record eyebrow and h1; registry and breadcrumb; MyCasesPage rows and tab roles.
- [x] 3.5 Resident menu groups from the portal, with an Overzicht item; App.vue hands them in.

## 4. Tests
- [x] 4.1 `tests/Unit/Contribution/BoardKeysTest.php`.
- [x] 4.2 `tests/zuiddrecht-resident-boards.spec.mjs` (`check:resident-boards`): each display, each moved thing's route, and the unchanged default render.
- [x] 4.3 `tests/example-site.spec.mjs`: the Zuiddrecht portal's menu groups and rows display.

## 5. Verify
- [ ] 5.1 Live on :8097 with dossiq declaring the keys (named in the PR), board beside render at 1440 and 390. — not run: needs a live instance with dossiq
