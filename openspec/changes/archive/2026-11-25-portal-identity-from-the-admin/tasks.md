# Tasks: portal-identity-from-the-admin

Wave 1. Rows 6.22 and 15.8. Decision D3 (TOOI half). Kind: code. Build rules:
`openspec/woo-build-rules.md`.

A test marked **fails today** must be run on `origin/development` first and seen red; put the failing
line in the PR body. Node tests run with `node --test` and are wired into `check:specs`; a test file
that is not in `check:specs` does not run in CI.

## 1. The images

- [x] 1.1 Declare `favicon` and `heroImage` (media references) on `#portal` in
  `lib/Settings/portaliq_register.json`, widen `logo` to accept a media reference, and bump the
  register version. `npm run check:register` exits 0 (REQ-PIA-001).
  - unit `tests/Unit/Settings/PortaliqRegisterConfigTest.php`
    `testThePortalDeclaresFaviconAndHeroImage`.
- [x] 1.2 The portal settings form gets three media pickers using the media library's existing
  picker and upload. The save path refuses a favicon type other than PNG, SVG or ICO and a media
  object of another portal (REQ-PIA-001).
  - **fails today**: `tests/Unit/Service/PortalSettingsServiceTest.php` (or the service the form
    saves through; find it first) `testAJpegFaviconIsRefused`,
    `testAnotherPortalsMediaIsRefused`.
  - Through the caller: the controller the form posts to, `testSavingTheThreeImagesStoresReferences`.
  - Save path built: the portal form is a manifest page that writes the `portal` object through
    OpenRegister, so the rules run on OpenRegister's creating/updating events
    (`PortalImagesGuardListener` over `lib/Service/Cms/PortalImageChoice.php`): a `media:<id>` must be a
    published item of this portal's own library, and a favicon's newest file a PNG, SVG or ICO.
    Tests in `tests/Unit/Service/Cms/PortalImageChoiceTest.php`.
  - Pickers built: the portal details form (`portal-settings-details` in `src/manifest.json`) keeps its
    logo picker and gains `favicon` and `heroImage` reference pickers over this portal's published
    images, stored as `media:<id>`; node test `tests/portal-details.spec.mjs`. The settings-form
    reference field has no upload of its own: an image is uploaded on the portal's Media page and then
    picked here.

## 2. The head and the hero

- [x] 2.1 Move the favicon choice out of `templates/site.php` into a small tested class
  (for example `lib/Service/Cms/SiteIcon.php`) that resolves favicon, logo, theme icon, own mark in
  that order and answers a public URL. The template calls it (REQ-PIA-002).
  - **fails today**: `tests/Unit/Service/Cms/SiteIconTest.php` `testTheFaviconWinsOverTheLogo`,
    `testAMediaReferenceBecomesAPublicUrl`, `testNothingSetFallsBackToTheThemeThenTheOwnMark`.
  - e2e `tests/e2e/portal-identity.spec.ts`: an anonymous visitor's page has one `link[rel=icon]`
    whose URL answers 200 without cookies. Cite REQ-PIA-002.
- [x] 2.2 The shell hands `#portal.heroImage` to the hero block when the block has no image
  (REQ-PIA-002).
  - **fails today**: node test `tests/site-shell-blocks.spec.mjs`
    `a hero without its own image uses the portal's`.
  - Built: the content API (`ContentController::site()`) sends `logo` and `heroImage` as public
    addresses, never as the `media:<id>` reference. The e2e spec of 2.1 is not added: it needs a
    live instance.

## 3. The organisation type

- [x] 3.1 Declare `organisationType` and `organisationTypeLabel` on `#portal`. The settings picker reads
  the TOOI organisation type scheme from OpenRegister's concept register through OpenRegister's
  public PHP API; read the real class on openregister `development` first and name it in the PR
  body. When the scheme is missing it shows a sentence and offers nothing (REQ-PIA-003).
  - **fails today**: `tests/Unit/Service/OrganisationTypeOptionsTest.php`
    `testTheOptionsComeFromTheConceptRegister`, `testAMissingSchemeOffersNothing`. Build the
    OpenRegister double from the real class signature, not from a local stub.
  - Built (server half): `#portal.organisationType` (uri) and `organisationTypeLabel` (register 0.112.0,
    portal 0.30.0); `lib/Service/OrganisationTypeOptions.php` reads the scheme through OpenRegister's
    `OCA\OpenRegister\Service\Vocabulary\ConceptRepository` (`scheme()`, `conceptsOf()`; the bound
    `ConceptRepositoryInterface` declares only `schemeByReference()`), looked up lazily. The scheme uri is
    `OrganisationTypeOptions::DEFAULT_SCHEME`, overridable by the app-config key `organisationTypeScheme`
    because no TOOI organisation type scheme is in OpenRegister's or OpenCatalogi's bundled lists yet.
    `PortalOrganisationTypeListener` settles every portal save: a concept of the scheme stores its label,
    anything else is refused, and without the list only the stored type is kept.
  - Left: the picker in the portal details form. The settings-form widget reads choices only from an
    OpenRegister register and schema, and a concept's `inScheme` holds the scheme's uuid, which differs per
    instance, so it cannot list one scheme's concepts; it needs a choices source in the widget library.
  - 2026-11-15 built (the picker): every portal save also stores the installed scheme's uuid as
    `#portal.organisationTypeSchemeId` (register 0.116.0, portal 0.33.0;
    `OrganisationTypeOptions::settle()`, removed when the scheme is missing or unreadable), and the
    details form's `organisationType` field is a reference over OpenRegister's `vocabulary` register,
    schema `concept`, filtered `inScheme: @object.organisationTypeSchemeId` (the widget's own record
    token), labelled `prefLabel.nl`, storing the concept's `uri`. Without the scheme the filter stays
    unresolved, the picker offers nothing and its hint says why. Tests: `OrganisationTypeOptionsTest`
    (`testASaveStoresTheInstalledSchemesUuid`, `testAnUnreadableSchemeStoresNoUuid`) and
    `tests/portal-details.spec.mjs`. A portal saved before this change gets the uuid on its next save.
- [x] 3.2 Replace "Van de gemeente" in `src/site/components/mijn/strings.js` and every other place
  the site names the organisation's kind with the label and its article; add the footer line and
  `DCTERMS.creator` (REQ-PIA-003).
  - **fails today**: node test `tests/mijn-components.spec.mjs`
    `a water authority is not called a municipality` and `no type reads van de organisatie`.
  - `grep -rn -i "de gemeente" src/site` answers nothing that is not inside a test or a
    portal-authored string; put the grep output in the PR body.
  - Strings in nl, en and en_US; `npm run check:l10n-js` exits 0.
  - Built: `src/site/components/mijn/organisationKind.js` names the kind with its article ("het
    waterschap", "de gemeente", "de organisatie" without a type); the documents' "Van ..." line
    (`fileLine`) and a mandate's "gegeven door ..." line read the portal's `organisationTypeLabel`, which
    `ContentController::site()` sends and `App.vue` provides; the report pages say "de organisatie".
    Node tests in `tests/mijn-components.spec.mjs`.
  - Built: `<meta name="DCTERMS.creator">` in `templates/site.php` from `SiteShell`'s `creator`
    (`lib/Service/Cms/SiteCreator.php`: the organisation's name, else the portal's title, with the type
    label), tests in `tests/Unit/Service/Cms/SiteCreatorTest.php`, `SiteShellTest` and
    `tests/site-head.spec.mjs`.
  - Built 25 Nov 2026 (the footer line), on a decision taken here: the legal band, under the colophon,
    reads "Een website van het waterschap". The content API words it (`SiteCreator::kindLine()`, sent
    as `organisationKindLine` by `ContentController::site()` in the page language) and
    `FooterColumns.vue` draws it (`data-testid="site-footer-kind"`, prop `kindLine` from `App.vue`), so
    the site entry stays inside its size budget; `IdleWarningDialog` now loads on demand to make room.
    It is no footer key: the portal authors nothing for it, and an authored colophon stays as written.
    No type, or a page in another language, draws no line (the label is Dutch). Tests:
    `SiteCreatorTest::testTheFooterLineNamesTheKindWithItsArticle`,
    `::testNoTypeOrAnotherLanguageHasNoFooterLine`, `ContentControllerTest::testSiteSendsTheFooterKindLine`,
    node `tests/site-shell-blocks.spec.mjs` `the footer names the organisation type: een website van het
    waterschap`.

## 4. Live

- [x] 4.1 Live check after merge on the dev instance: set a favicon, a hero image and the type
  waterschap on one portal; record the head's icon link, a screenshot of the hero and the "Van het
  waterschap" line.
  — done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main
  and portaliq development, portal open-tilburg seeded by `tests/e2e/ci-seed.sh`. Two published
  images in its Media (a 64x64 PNG, a 1440x480 JPEG). In the portal's Instellingen the Tabbladpictogram
  and Hero-afbeelding pickers list them; Opslaan PATCHes `favicon` and `heroImage` as `media:<id>`. A
  JPEG as favicon is refused with 422 "A favicon must be a PNG, SVG or ICO file." (REQ-PIA-001).
  Head: exactly one `<link rel="icon" href=".../api/content/media/<favicon id>?portal=open-tilburg">`,
  which answers 200 `image/png` without a session. A hero block without its own image, added to the
  home page, draws the portal's hero image as its background
  (`url(.../api/content/media/<hero id>?portal=open-tilburg)`) (REQ-PIA-002); the sandbox has no theme
  styles, so the screenshot is plain. Soort organisatie: the TOOI organisation type scheme is not in
  OpenRegister's bundled lists, so the picker first offered nothing with its hint (as specified); a
  two-concept subset (waterschap, gemeente) under `https://identifier.overheid.nl/tooi/set/scw_organisatietypen`
  was added to the vocabulary register as sandbox seed (through the objects API: `occ
  openregister:vocabulary:import-csv` is refused as user 'Anonymous'), after which the picker offered
  both; choosing waterschap stores the uri, `organisationTypeLabel` "waterschap" and the scheme's uuid.
  The site then reads "Een website van het waterschap" in the footer and
  `<meta name="DCTERMS.creator" content="dev-org (waterschap)">` (REQ-PIA-003). The signed-in "Van het
  waterschap" line sits on case documents, which need a dossiq case on the sandbox; it stays covered
  by `tests/mijn-components.spec.mjs` (46 node tests green here).

## 5. Verify and deliver

- [x] 5.1 `TMPDIR` set to a sibling directory beside the clone.
- [x] 5.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the
  touched classes and `node --test` on the touched node tests. Judge PHPUnit by the `Tests:` line.
- [x] 5.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site` (the entry budget), plus
  any other leg `code-quality.yml` requires (read the workflow). Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [x] 5.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [x] 5.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on any
  commit. Done means merged on `development` with CI green. 6.22 and 15.8 then read `yes` (build),
  and `production` only with a store release.

> 5.1 to 5.5 for the live round (25 Nov 2026, live-portaliq-1): this round changes only openspec
> files and `@spec` paths, so no new statements and no coverage arithmetic; PHPUnit SiteIcon,
> SiteCreator, OrganisationTypeOptions and PortalImageChoice 27/27; hydra diff-check with only the
> inherited gate-23 finding; one PR on development, merged.
