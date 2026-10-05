# Tasks: site-accessibility-statement

Wave 1. Rows 6.7 and 15.2. Kind: code. Build rules: `~/memcap-work/woo-build/LANE-RULES-BUILD.md`.

Load the hydra `writing` skill before writing the statement's sentences: they are user-facing copy.
A test marked **fails today** must be run on `origin/development` first and seen red.

## 1. The measurement

- [ ] 1.1 Add schema `accessibilityMeasurement` and portal settings `accessibilityAudit`
  (`{party, date, reportUrl, result}`), `accessibilityRegisterUrl` and `accessibilityPages` to
  `lib/Settings/portaliq_register.json`; bump the register version (REQ-SAS-001, REQ-SAS-003).
  - unit `PortaliqRegisterConfigTest::testTheMeasurementSchemaIsDeclared`.
- [ ] 1.2 Admin "Measure accessibility": a component that frames each page in turn, injects axe-core
  from a lazy chunk, runs it with the five tags on `[data-testid=site-root]`, and posts the result.
  `scripts/check-site-chunks.js` must still pass (axe not in the site entry) (REQ-SAS-001).
  - node test `tests/accessibility-measure.spec.mjs` (wire into `check:specs`):
    `the five tags are the ones the e2e suite uses` (read both files),
    `a page that cannot be framed is posted as not measured`.
- [ ] 1.3 `AccessibilityController::store()` on POST `/api/portals/{portal}/accessibility/measurements`,
  admin or portal-manager only, validating the shape (REQ-SAS-001).
  - **fails today** (route absent): `tests/Unit/Controller/AccessibilityControllerTest.php`
    `testARunIsStoredWithItsEvidence`, `testANonManagerIsRefused`,
    `testAnUnmeasuredPageIsKeptAsNotMeasured`.
  - Gates route-auth and semantic-auth must pass.

## 2. The statement

- [ ] 2.1 `lib/Service/Cms/AccessibilityStatement.php` builds the statement model from the latest
  measurement, the audit and the portal (organisation from `#portal.organisation`, and the type label
  once `portal-identity-from-the-admin` lands). Rule ids map to plain-language Dutch and English
  sentences in one table; an unmapped rule falls back to axe's own help text with its link
  (REQ-SAS-002, REQ-SAS-003).
  - **fails today**: `tests/Unit/Service/Cms/AccessibilityStatementTest.php`
    `testKnownIssuesFollowTheMeasurement`, `testANewerMeasurementReplacesTheIssues`,
    `testNoAuditMeansAtMostC`, `testAnAuditSupportsB`, `testAnAuditOlderThanThreeYearsSupportsNothing`.
- [ ] 2.2 Serve it at `/toegankelijkheid` on every portal through the site router as a system page,
  public, and add the footer link (REQ-SAS-002).
  - Through the caller: e2e `tests/e2e/site-accessibility.spec.ts` gains
    `the statement is linked from the footer and lists the measurement date`, and the page itself
    passes the same axe check. Cite REQ-SAS-002.
- [ ] 2.3 The admin form records the audit and the register URL (REQ-SAS-003).
  - node test `the admin refuses an A or B claim without an audit`.

## 3. Live

- [ ] 3.1 Live check after merge on the dev instance: run a measurement on one portal, open
  `/toegankelijkheid` anonymously, and record the status line and the known issues.

## 4. Verify and deliver

- [ ] 4.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 4.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 4.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site`, plus any other leg
  `code-quality.yml` requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`;
  count the gates that ran.
- [ ] 4.4 Project coverage of the added statements as LANE-RULES-BUILD says.
- [ ] 4.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.7 and 15.2 then read `yes` (build), and `production` only with a
  store release.
