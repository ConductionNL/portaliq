# Tasks: portal-traffic-zero-result-searches

Wave 1. Row 16.3. Kind: code. Build rules: `~/memcap-work/woo-build/LANE-RULES-BUILD.md`.

A test marked **fails today** must be run on `origin/development` first and seen red; put the failing
line in the PR body.

## 1. The roll-up

- [ ] 1.1 Declare `zeroResultSearches` (array of `{term, count}`) and `searchesWithoutCount`
  (integer) on `portalTrafficDaily` in `lib/Settings/portaliq_register.json`; bump the register
  version (REQ-PZR-001).
  - unit `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
    `testTheDailyRecordDeclaresZeroResultSearches`.
- [ ] 1.2 First confirm where the client puts `results` on the stored event (top level or `params`):
  read `src/traffic/` and one stored `portalTrafficEvent` on the dev instance, and name it in the PR
  body. Then add the dimension in `TrafficRollup`, reading the count as an integer only, and the sum in
  `TrafficRollupSum` (REQ-PZR-001).
  - **fails today**: `tests/Unit/Service/Traffic/TrafficRollupTest.php`
    `testTwoTermsFoundNothing`, `testAnUnknownCountIsNotAZero`, `testAStringZeroIsNotReadAsUnknown`
    (decide and assert what a `"0"` string is; the client sends a number, so `"0"` is unknown).
  - `tests/Unit/Service/Traffic/TrafficRollupSumTest.php` `testARollupPortalSumsItsMembers`.
  - Through the caller: the job that writes the daily record (find it; it calls `TrafficRollup`),
    `testTheDailyJobStoresZeroResultSearches`, asserting the stored record, not the method's return.

## 2. The Traffic page and the export

- [ ] 2.1 Add the "Gezocht, niets gevonden" list to the Traffic page with the period sum and the
  public search link built from the portal's search page (`shellData.js` knows it). Show the
  without-count sentence when it applies (REQ-PZR-002).
  - node test `tests/traffic-summary.spec.mjs` `zero result terms are summed over the period` and
    `the link opens the public search with the term`.
  - Strings in nl, en and en_US; `npm run check:l10n-js` exits 0.
- [ ] 2.2 The daily export includes both keys (REQ-PZR-002).
  - unit on the export service: `testTheExportCarriesZeroResultSearches`.

## 3. End to end and live

- [ ] 3.1 e2e `tests/e2e/traffic-zero-results.spec.ts`: search a nonsense term on the site, run the
  roll-up, and find the term on the Traffic page. Cite REQ-PZR-001 and REQ-PZR-002.
- [ ] 3.2 Live check after merge on the dev instance: one zero-result search on a portal with
  traffic on; record the daily record's `zeroResultSearches` through the OpenRegister API.

## 4. Verify and deliver

- [ ] 4.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 4.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 4.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register` and `npm run check:specs`, plus any other leg `code-quality.yml` requires.
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 4.4 Project coverage of the added statements as LANE-RULES-BUILD says.
- [ ] 4.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 16.3 then reads `yes` (build), and `production` only with a store
  release.
