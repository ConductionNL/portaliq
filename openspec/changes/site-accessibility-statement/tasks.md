# Tasks: site-accessibility-statement

Wave 1. Rows 6.7 and 15.2. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Load the hydra `writing` skill before writing the statement's sentences: they are user-facing copy.
A test marked **fails today** must be run on `origin/development` first and seen red.

## 1. The measurement

- [x] 1.1 Add schema `accessibilityMeasurement` and portal settings `accessibilityAudit`
  (`{party, date, reportUrl, result}`), `accessibilityRegisterUrl` and `accessibilityPages` to
  `lib/Settings/portaliq_register.json`; bump the register version (REQ-SAS-001, REQ-SAS-003).
  - unit `PortaliqRegisterConfigTest::testTheMeasurementSchemaIsDeclared`.
  - Built: register 0.91.0, portal 0.23.0, `SchemaTenancy` scope portal, action `portal.measure-accessibility` seeded (admin).
- [x] 1.2 Admin "Measure accessibility": a component that frames each page in turn, injects axe-core
  from a lazy chunk, runs it with the five tags on `[data-testid=site-root]`, and posts the result.
  `scripts/check-site-chunks.js` must still pass (axe not in the site entry) (REQ-SAS-001).
  - node test `tests/accessibility-measure.spec.mjs` (wire into `check:specs`):
    `the five tags are the ones the e2e suite uses` (read both files),
    `a page that cannot be framed is posted as not measured`.
  - Built: `src/widgets/PortalAccessibility.vue` on the portal page, `src/lib/accessibilityMeasure.js`, `tests/accessibility-measure.spec.mjs` (8 tests, `check:accessibility-measure`). The site may be framed by its own origin only for `?measure=1` from a user who may measure (`AccessibilityFraming`, `PortalPageControllerTest`). axe-core is injected into the frame as a script element; whether the site's CSP admits it is for the live check (3.1); a refusal posts the page as not measured. The pages are home, the search page, a not-found probe and the added pages: there is no server-side way to pick "one publication page", so the admin adds one (the field says so). `scripts/check-site-chunks.js` needs built assets: not run locally (no local build), CI's Frontend Build answers it.
- [x] 1.3 `AccessibilityController::store()` on POST `/api/portals/{portal}/accessibility/measurements`,
  admin or portal-manager only, validating the shape (REQ-SAS-001).
  - **fails today** (route absent): `tests/Unit/Controller/AccessibilityControllerTest.php`
    `testARunIsStoredWithItsEvidence`, `testANonManagerIsRefused`,
    `testAnUnmeasuredPageIsKeptAsNotMeasured`.
  - Gates route-auth and semantic-auth must pass.
  - Built: `lib/Controller/AccessibilityController.php` (index, update, store; `#[NoAdminRequired]` + the action matrix), `tests/Unit/Controller/AccessibilityControllerTest.php` (5 tests, red before: build-round/red-sas-controller.txt). Every stored row is validated against the real schema fragment.

## 2. The statement

- [x] 2.1 `lib/Service/Cms/AccessibilityStatement.php` builds the statement model from the latest
  measurement, the audit and the portal (organisation from `#portal.organisation`, and the type label
  once `portal-identity-from-the-admin` lands). Rule ids map to plain-language Dutch and English
  sentences in one table; an unmapped rule falls back to axe's own help text with its link
  (REQ-SAS-002, REQ-SAS-003).
  - **fails today**: `tests/Unit/Service/Cms/AccessibilityStatementTest.php`
    `testKnownIssuesFollowTheMeasurement`, `testANewerMeasurementReplacesTheIssues`,
    `testNoAuditMeansAtMostC`, `testAnAuditSupportsB`, `testAnAuditOlderThanThreeYearsSupportsNothing`.
  - Built with `AccessibilityRuleSentences` (28 rules, nl and en); red before: build-round/red-sas-statement.txt. The organisation is the portal's `organisation` value until `portal-identity-from-the-admin` lands.
- [ ] 2.2 (not run: the e2e needs a live instance; the page, the route, the footer link and `tests/site-accessibility-statement.spec.mjs` (6 tests) are built, and the e2e test is written) Serve it at `/toegankelijkheid` on every portal through the site router as a system page,
  public, and add the footer link (REQ-SAS-002).
  - Through the caller: e2e `tests/e2e/site-accessibility.spec.ts` gains
    `the statement is linked from the footer and lists the measurement date`, and the page itself
    passes the same axe check. Cite REQ-SAS-002.
- [x] 2.3 The admin form records the audit and the register URL (REQ-SAS-003).
  - node test `the admin refuses an A or B claim without an audit`.

## 3. Live

- [ ] 3.1 (not run: needs a live instance) Live check after merge on the dev instance: run a measurement on one portal, open
  `/toegankelijkheid` anonymously, and record the status line and the known issues.

## 4. Verify and deliver

- [x] 4.1 `TMPDIR` set to a sibling directory beside the clone.
- [x] 4.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 4.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site`, plus any other leg
  `code-quality.yml` requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`;
  count the gates that ran.
- [ ] 4.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [ ] 4.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.7 and 15.2 then read `yes` (build), and `production` only with a
  store release.
