# Tasks: portal-identity-from-the-admin

Wave 1. Rows 6.22 and 15.8. Decision D3 (TOOI half). Kind: code. Build rules:
`openspec/woo-build-rules.md`.

A test marked **fails today** must be run on `origin/development` first and seen red; put the failing
line in the PR body. Node tests run with `node --test` and are wired into `check:specs`; a test file
that is not in `check:specs` does not run in CI.

## 1. The images

- [ ] 1.1 Declare `favicon` and `heroImage` (media references) on `#portal` in
  `lib/Settings/portaliq_register.json`, widen `logo` to accept a media reference, and bump the
  register version. `npm run check:register` exits 0 (REQ-PIA-001).
  - unit `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
    `testThePortalDeclaresFaviconAndHeroImage`.
- [ ] 1.2 The portal settings form gets three media pickers using the media library's existing
  picker and upload. The save path refuses a favicon type other than PNG, SVG or ICO and a media
  object of another portal (REQ-PIA-001).
  - **fails today**: `tests/Unit/Service/PortalSettingsServiceTest.php` (or the service the form
    saves through; find it first) `testAJpegFaviconIsRefused`,
    `testAnotherPortalsMediaIsRefused`.
  - Through the caller: the controller the form posts to, `testSavingTheThreeImagesStoresReferences`.

## 2. The head and the hero

- [ ] 2.1 Move the favicon choice out of `templates/site.php` into a small tested class
  (for example `lib/Service/Cms/SiteIcon.php`) that resolves favicon, logo, theme icon, own mark in
  that order and answers a public URL. The template calls it (REQ-PIA-002).
  - **fails today**: `tests/Unit/Service/Cms/SiteIconTest.php` `testTheFaviconWinsOverTheLogo`,
    `testAMediaReferenceBecomesAPublicUrl`, `testNothingSetFallsBackToTheThemeThenTheOwnMark`.
  - e2e `tests/e2e/portal-identity.spec.ts`: an anonymous visitor's page has one `link[rel=icon]`
    whose URL answers 200 without cookies. Cite REQ-PIA-002.
- [ ] 2.2 The shell hands `#portal.heroImage` to the hero block when the block has no image
  (REQ-PIA-002).
  - **fails today**: node test `tests/site-shell-blocks.spec.mjs`
    `a hero without its own image uses the portal's`.

## 3. The organisation type

- [ ] 3.1 Declare `organisationType` and `organisationTypeLabel` on `#portal`. The settings picker reads
  the TOOI organisation type scheme from OpenRegister's concept register through OpenRegister's
  public PHP API; read the real class on openregister `development` first and name it in the PR
  body. When the scheme is missing it shows a sentence and offers nothing (REQ-PIA-003).
  - **fails today**: `tests/Unit/Service/OrganisationTypeOptionsTest.php`
    `testTheOptionsComeFromTheConceptRegister`, `testAMissingSchemeOffersNothing`. Build the
    OpenRegister double from the real class signature, not from a local stub.
- [ ] 3.2 Replace "Van de gemeente" in `src/site/components/mijn/strings.js` and every other place
  the site names the organisation's kind with the label and its article; add the footer line and
  `DCTERMS.creator` (REQ-PIA-003).
  - **fails today**: node test `tests/mijn-components.spec.mjs`
    `a water authority is not called a municipality` and `no type reads van de organisatie`.
  - `grep -rn -i "de gemeente" src/site` answers nothing that is not inside a test or a
    portal-authored string; put the grep output in the PR body.
  - Strings in nl, en and en_US; `npm run check:l10n-js` exits 0.

## 4. Live

- [ ] 4.1 Live check after merge on the dev instance: set a favicon, a hero image and the type
  waterschap on one portal; record the head's icon link, a screenshot of the hero and the "Van het
  waterschap" line.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site` (the entry budget), plus
  any other leg `code-quality.yml` requires (read the workflow). Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 5.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [ ] 5.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on any
  commit. Done means merged on `development` with CI green. 6.22 and 15.8 then read `yes` (build),
  and `production` only with a store release.
