# Tasks: close-portaliq-register-to-staff

- [x] 1.1 Register block naming only `portaliq-medewerkers`; every `authenticated` schema rule becomes `portaliq-medewerkers`; `export` follows `read`; register version 0.97.0.
  - unit: `RegisterAccessDeclaredTest`, `PortaliqRegisterConfigTest`
- [x] 1.2 Repair step `SeedStaffGroup` creates and seeds the staff group once, registered for install and upgrade.
  - unit: `SeedStaffGroupTest`
- [x] 1.3 `PageEditorService` adds the editor groups to `read` and removes groups taken out of the setting.
  - unit: `PageEditorServiceTest::testARemovedEditorGroupLosesTheRead`
- [x] 1.4 `AdminMenuAccess::isStaff()`; `DashboardController` refuses non-staff with a 403 after the resident redirect, failing closed without the access service.
  - unit: `AdminMenuAccessTest`, `DashboardControllerTest`
- [x] 1.5 Every OpenRegister call on a portal path passes `_rbac: false`, held to a reviewed list.
  - unit: `RegisterAccessDeclaredTest::testEveryPortalPathReadsWithTheChecksOff`
- [x] 2.1 (live pass, after merge and re-import) The probe in the PR body returns 0 for an account in no group and for a member of `inwoners`, and an editor still opens the site editor.
  - Live pass 2026-11-17 (fresh NC 34.0.6, openregister main, register 0.121.0, editor group `redactie` set through `PUT /api/settings`): the OpenRegister objects API answers 200 with 0 rows to an account in no group and to an `inwoners` member on portalAccessRequest, portalSubmission, portalAccount, portalMessage, portalNotification, portalMandate, portal, newsItem, form, portalFormBinding and portalSession (admin: 1, 3, 1, 6, 3, 2 and 8 rows where the rig has any). page and menu stay public (11 published pages, the editor reads the 12th, the draft). `/apps/portaliq/` answers 403 to both, 200 to the editor, and `GET /api/cms/editing-context` gives the editor `canEdit: true`.
