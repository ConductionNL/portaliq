# Tasks: woo-intake-delivers-to-dossiq

Wave 3. Supporting: keeps 7.1 and 7.2 yes. Decisions D1 and D12. Kind: code. Build rules:
`~/memcap-work/woo-build/LANE-RULES-BUILD.md`.

**Do not start before** `dossiq/woo-request-takes-over-from-opencatalogi` is merged on dossiq
`development`. Read `OCA\Dossiq\Portal\PortalContributionProvider::receiveWooRequest()` there and copy
its signature and docblock return shape into the PR body. If the method is missing or differs from
`receiveWooRequest(array $answers, string $receivedAt = ''): array` with the keys
`outcome, requestId, reference, dueAt, message`, stop and say so. A test marked **fails today** must be
run on `origin/development` first and seen red.

## 1. Repoint the delivery

- [ ] 1.1 `PortalWooRequestDelivery`: `APP` becomes `dossiq`; the unavailable sentences name dossiq; the
  answer reading is unchanged. Update the class and method docblocks and `@spec` tags to this change
  (REQ-WID-001, REQ-WID-002).
  - **fails today**: `tests/Unit/Service/Intake/PortalWooRequestDeliveryTest.php`
    `testTheRequestReachesDossiqsProviderWithTheMomentItWasSent`,
    `testOpencatalogiIsNeverLocated` (assert `locate()` is called only with `dossiq`),
    `testWithoutDossiqTheOutcomeIsUnavailableAndNamesDossiq`,
    `testADossiqWithoutTheMethodIsUnavailable`. Keep the existing answer-reading tests, renamed.
  - Contract: `tests/Unit/Service/Intake/DossiqWooProviderContractTest.php` asserts the method name,
    the two parameters and the five keys as literals copied from dossiq's class with its commit sha.
    dossiq's `PortalContributionProviderTest::testReceiveWooRequestHasOpencatalogisSignature` tests
    the same on its side; name it in the PR body.
- [ ] 1.2 Through the caller: `tests/Unit/BackgroundJob/PortalIntakeDeliveryJobTest.php`
  `testAWooBindingIsRegisteredWithDossiqsNumberAndDueDate` and
  `testWithoutDossiqAWooSubmissionIsFailedWithTheReason`, built on the real
  `PortalWooRequestDelivery` with a locator double, not on a mock of the delivery.

## 2. Tell the administrator

- [ ] 2.1 Rewrite the `deliverTo` description in `lib/Settings/portaliq_register.json` to name dossiq and
  bump the register version. The form binding screen shows the dossiq-missing sentence on every
  `wooRequest` binding while dossiq is not installed (REQ-WID-002).
  - node test `tests/form-binding-preview.spec.mjs` `a woo binding without dossiq says so`.
  - `npm run check:register` exits 0.

## 3. The default entry

- [ ] 3.1 Where portaliq provisions a home tile or Woo page (`LandingPageProvisioningService` and the
  start tiles), use dossiq's `startWooVerzoekAlgemeen` when dossiq is installed and nothing when it is
  not (REQ-WID-003).
  - **fails today**: `tests/Unit/Service/LandingPageProvisioningServiceTest.php`
    `testTheWooTileOpensDossiqsAction`, `testWithoutDossiqThereIsNoWooTile`.

## 4. End to end and live

- [ ] 4.1 e2e `tests/e2e/woo-intake-to-dossiq.spec.ts` on the dev rig with dossiq installed: send a Woo
  form, run the delivery job, and see the reference page quote dossiq's case number and due date.
  Cite REQ-WID-001.
- [ ] 4.2 Live check after merge on the dev instance: one Woo form delivered; record the submission's
  `externalReference` and `dueAt`, and the dossiq case's `deadline` read through the OpenRegister API.
  They must match.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register` and `npm run check:specs`, plus any other leg `code-quality.yml` requires.
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 5.4 Project coverage of the added statements as LANE-RULES-BUILD says.
- [ ] 5.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 7.1 and 7.2 stay yes; through dossiq, `production` only once both
  store releases carry the move. The release notes say: without dossiq, a portal no longer takes Woo
  requests (D12).
