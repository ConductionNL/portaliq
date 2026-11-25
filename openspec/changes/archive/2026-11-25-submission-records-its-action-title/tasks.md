# Tasks: submission-records-its-action-title

- [x] 1.1 `SubmissionReceiptService::record()` writes `actionTitle` on the proof log and the fallback record (string, trimmed, at most 200 characters, left out when blank)
  - Files: lib/Service/SubmissionReceiptService.php
  - Test: tests/Unit/Service/SubmissionReceiptServiceTest.php
- [x] 1.2 The create and endpoint paths pass the matched action's `label`
  - Files: lib/Controller/ContributionController.php
  - Test: tests/Unit/Controller/ContributionControllerTest.php
- [x] 1.3 A task completion passes the task's title
  - Files: lib/Controller/PortalTaskProxyController.php
  - Test: tests/Unit/Controller/PortalTaskProxyControllerTest.php
- [x] 1.4 Live: after the portaliq register load (0.104.0), submit a create action on Mijn Zuiddrecht and read its row on Inzendingen by its title
  - Done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (MariaDB, register 0.128.0) with pipelinq, dossiq and the Zuiddrecht example set: Sanne sent three questions through pipelinq's create action `askQuestion` on `/contact/vraag`; each `portalSubmission` row carries `actionTitle` "Stel een vraag", and the admin page Inzendingen lists the three under Handeling as "Stel een vraag", app pipelinq, Bevestigd.
