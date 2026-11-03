# Tasks: submission-row-actions

- [ ] 1.1 `portalSubmission` gains optional `formBinding` (uuid, "The request form this submission came through"); schema 0.4.0, register version bump
  - Files: lib/Settings/portaliq_register.json
  - Test: tests/Unit/Settings (schema version pin)
- [ ] 1.2 `SubmissionReceiptService::registerAgain()` writes the receipt for one stored record and marks it delivered; a failure keeps it failed
  - Files: lib/Service/SubmissionReceiptService.php
  - Test: tests/Unit/Service/SubmissionReceiptServiceTest.php
- [ ] 1.3 `POST /api/submissions/{id}/register-again`: staff check first, 404 for an unreadable record, 409 for a delivered one
  - Files: lib/Controller/SubmissionAdminController.php, appinfo/routes.php
  - Test: tests/Unit/Controller/SubmissionAdminControllerTest.php
- [ ] 1.4 A write guard refuses a `portalSubmission` update that changes a proof field
  - Files: lib/Listener/SubmissionWriteGuardListener.php, lib/AppInfo/Application.php
  - Test: tests/Unit/Listener/SubmissionWriteGuardListenerTest.php
- [ ] 2.1 nc-vue: an `api-call` can open what the call answered (`openResponse`), if the dispatcher still lacks it
  - Files: nextcloud-vue src/utils/actionsDispatcher.js, manifest schema
- [ ] 2.2 `PortalSubmissions.config.actions`: Edit, Link a form, Conversation in Talk, Register again, then Copy and Delete; visibility per deliveryStatus and per installed app
  - Files: src/manifest.json, l10n/en.json, l10n/nl.json
  - Test: tests/submission-row-actions.spec.mjs
- [ ] 3.1 Live: on PtInzendingen, register a failed row again, start a Talk room and link a form; the open row menu matches the board
