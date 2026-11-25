# Tasks: portal-traffic-zero-result-searches

Wave 1. Row 16.3. Kind: code. Build rules: `openspec/woo-build-rules.md`.

A test marked **fails today** must be run on `origin/development` first and seen red; put the failing
line in the PR body.

## 1. The roll-up

- [x] 1.1 Declare `zeroResultSearches` (array of `{term, count}`) and `searchesWithoutCount`
  (integer) on `portalTrafficDaily` in `lib/Settings/portaliq_register.json`; bump the register
  version (REQ-PZR-001).
  - unit `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
    `testTheDailyRecordDeclaresZeroResultSearches`.
- [x] 1.2 First confirm where the client puts `results` on the stored event (top level or `params`):
  read `src/traffic/` and one stored `portalTrafficEvent` on the dev instance, and name it in the PR
  body. Then add the dimension in `TrafficRollup`, reading the count as an integer only, and the sum in
  `TrafficRollupSum` (REQ-PZR-001).
  - Where `results` sits: the client sends it in `params.results` (`client.js` only lifts searchTerm, linkUrl and fileName); the rollup reads `results` first, then `params.results`. Read from the code, not from a stored event on a dev instance (not run: no live instance).
  - **fails today**: `tests/Unit/Service/Traffic/TrafficRollupTest.php`
    `testTwoTermsFoundNothing`, `testAnUnknownCountIsNotAZero`, `testAStringZeroIsNotReadAsUnknown`
    (decide and assert what a `"0"` string is; the client sends a number, so `"0"` is unknown).
  - `tests/Unit/Service/Traffic/TrafficRollupSumTest.php` `testARollupPortalSumsItsMembers`.
  - Through the caller: the job that writes the daily record (find it; it calls `TrafficRollup`),
    `testTheDailyJobStoresZeroResultSearches`, asserting the stored record, not the method's return.

## 2. The Traffic page and the export

- [x] 2.1 Add the "Gezocht, niets gevonden" list to the Traffic page with the period sum and the
  public search link built from the portal's search page (`shellData.js` knows it). Show the
  without-count sentence when it applies (REQ-PZR-002).
  - node test `tests/traffic-summary.spec.mjs` `zero result terms are summed over the period` and
    `the link opens the public search with the term`.
  - Strings in nl and en (en_US falls back to en, as for every other Traffic string); `npm run check:l10n-js` exits 0.
  - The link is built in `searchLinkOf` (trafficSummary.js) from the portal's `headerSearch.route`, else `/zoeken`.
- [x] 2.2 The daily export includes both keys (REQ-PZR-002).
  - unit on the export service: `testTheExportCarriesZeroResultSearches`.

## 3. End to end and live

- [x] 3.1 e2e `tests/e2e/traffic-zero-results.spec.ts`: search a nonsense term on the site, run the
  roll-up, and find the term on the Traffic page. Cite REQ-PZR-001 and REQ-PZR-002. — written, runs in nightly (`tests/e2e/traffic-zero-results.spec.ts`, three tests citing REQ-PZR-001 and REQ-PZR-002, inside the e2e config's testDir and not ignored); not verified on a live instance in this run.
- [x] 3.2 Live check after merge on the dev instance: one zero-result search on a portal with
  traffic on; record the daily record's `zeroResultSearches` through the OpenRegister API.
  — done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main
  and portaliq development, seeded by `tests/e2e/ci-seed.sh`: `tests/e2e/traffic-zero-results.spec.ts`
  ran live 3/3 (its occ calls through a local `docker exec` shim) after one spec fix: the without-count
  sentence was matched in English only, the Dutch page reads "1 zoekopdracht meldde niet hoeveel
  resultaten die vond." / "2 zoekopdrachten meldden niet ...". The daily record of open-tilburg for
  2026-11-25, read through `/apps/openregister/api/objects/portaliq/portalTrafficDaily`, holds
  `zeroResultSearches` `[{"term": "parkeervergunnig <run>", "count": 2}, {"term": "hondenbelasting <run>",
  "count": 1}, {"term": "qzxvbn<run>", "count": 1}, ...]` (no "parkeervergunning", which found results)
  and `searchesWithoutCount: 3` (one per run); the roll-up portal rollup-tilburg-venray carries the
  same sums (REQ-PZR-001, REQ-PZR-002).

## 4. Verify and deliver

- [x] 4.1 `TMPDIR` set to a sibling directory beside the clone (build round 2: `TMPDIR=../tmp` in the lane's PHPUnit runner; the 8 named PHPUnit tests and 2 node tests pass on 2594f608).
- [x] 4.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [x] 4.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register` and `npm run check:specs`, plus any other leg `code-quality.yml` requires.
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [x] 4.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [x] 4.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 16.3 then reads `yes` (build), and `production` only with a store
  release.

> 4.3 to 4.5 for the live round (25 Nov 2026, live-portaliq-1): this round changes only the e2e spec and
> this file, so no new statements and no coverage arithmetic; hydra diff-check shows only the inherited
> gate-23 finding (lib/Kernel/Contracts/AuditTrailServiceInterface.php, also red on development);
> `npm run lint` 0 errors; one PR on development, merged.
