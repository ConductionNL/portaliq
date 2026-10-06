# Tasks: publication-error-reports-and-withheld-notices

Wave 2. Rows 6.15 and 6.16. Decision D9. Kind: code. Build rules:
`openspec/woo-build-rules.md`.

**Before starting**, read on opencatalogi `development`: `publication-detail-for-the-portal` and
`publication-withdrawal-aftercare` (merged or not, and the exact 410 body), and whether any change
exposes withheld documents with their grounds on the public read. Write the answers in the PR body. A
test marked **fails today** must be run on `origin/development` first and seen red.

## 1. Settings

- [ ] 1.1 Declare `publicationErrorReports`, `publicationErrorReportOwner` and `showWithheldNotices` on
  `#portal`, defaults false and empty; refuse switching reports on without an existing owner. Bump the
  register version (REQ-PER-001, REQ-PER-003).
  - **fails today**: `tests/Unit/Service/PortalSettingsServiceTest.php` (or the service the portal
    form saves through) `testErrorReportsNeedAnOwner`, `testBothAreOffOnANewPortal`.

## 2. Error reports

- [ ] 2.1 Schema `publicationErrorReport` (status `new`, `handled`, `not-applicable`, `closedAt`).
  `PublicationReportController::create()` on POST `/api/site/publication-reports` with `#[PublicPage]`,
  `#[NoCSRFRequired]`, `#[AnonRateLimit]` and `#[BruteForceProtection]`; it checks the setting, the
  publication through the anonymous public read, the length, and stores no IP or user agent. It
  notifies the owner through Nextcloud's `INotificationManager` with a notifier registered in
  `Application.php` (REQ-PER-002).
  - **fails today** (route absent): `tests/Unit/Controller/PublicationReportControllerTest.php`
    `testOffAnswers404`, `testAReportReachesTheOwnerGroup`, `testADraftIsRefusedAndNothingStored`,
    `testATooLongDescriptionIsRefused`, `testNoIpAddressIsStored` (assert the stored object's keys).
  - Through the caller: `tests/Unit/Notification/PublicationReportNotifierTest.php`
    `testTheNotificationLinksToTheReport`, and `ApplicationTest::testTheNotifierIsRegistered`.
  - Gate route-auth green; a throttling e2e is not needed, the attributes are asserted by reflection in
    `testTheRouteIsRateLimited`.
- [ ] 2.2 "Fout melden" on the publication page (a dialog in its own file under `src/site/dialogs/` or
  the site's equivalent), only when on (REQ-PER-002).
  - node test `tests/publication-report.spec.mjs` (wire into `check:specs`): `no button when off`,
    `the form posts publication, document and description`.
- [ ] 2.3 The admin queue for the owner: list, mark handled or not applicable. A daily `TimedJob` erases
  `contactEmail` 30 days after `closedAt` (REQ-PER-002).
  - unit `tests/Unit/BackgroundJob/EraseReportContactJobTest.php` `testTheAddressIsErasedAfter30Days`,
    `testAnOpenReportKeepsItsAddress`.
  - Through the caller: `ApplicationTest` or `info.xml` test that the job is registered.

## 3. Withheld notices

- [ ] 3.1 On a 410 from the publication read, with the setting on, render a tombstone page with status
  410 from `{withdrawnAt, publicReason, title?}`; with it off, render not found (REQ-PER-003,
  REQ-PER-004).
  - **fails today**: node test `tests/publication-detail.spec.mjs` `a 410 with notices on shows the
    withdrawal`, `a 410 with notices off shows not found`, `a tombstone without a title shows none`.
  - Server side: the site route answers 410, not 200, for the tombstone; test it through the page
    controller: `testATombstoneAnswers410`.
- [ ] 3.2 The "Niet openbaar gemaakt" list (REQ-PER-005). If no opencatalogi change exposes withheld
  documents with grounds on the public read, build the rendering against a fixture whose keys are
  written in the PR body as the proposed contract, leave this box open, and say in the PR body that
  6.16's second half waits on opencatalogi.
  - node test `two withheld documents list their grounds`, `notices off lists nothing`.

## 4. Live

- [ ] 4.1 Live check after merge on the dev instance: switch both on for one portal, send one error report
  anonymously, read the owner's notification, and open one withdrawn publication's link. Record each.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site`, plus any other leg
  `code-quality.yml` requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`;
  count the gates that ran.
- [ ] 5.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [ ] 5.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.15 and 6.16 then read `yes` (build) as opt-in features (6.16's list
  half as stated in 3.2), and `production` only with a store release.
