# Tasks: site-honest-without-javascript

Wave 1. Row 6.6. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Load the hydra `writing` skill before writing the notice and the "needs JavaScript" sentences: they are user-facing
copy. A test marked **fails today** must be run on `origin/development` first and seen red. The e2e tests below use
Playwright's `test.use({ javaScriptEnabled: false })` in a new file `tests/e2e/site-without-javascript.spec.ts`, and
each test carries the `@e2e portaliq site-without-javascript::<scenario>` tag of the scenario it proves.

## 1. The notice on every page (REQ-SHJ-001)

- [ ] 1.1 In `templates/site.php`, directly after the skip link, emit `<noscript><main id="pq-main">` with the notice
  and a link to `/site/plain` carrying `route`, `portal`, `_search` and `_page` from the request (built by
  `PortalPageController::site()` as `plainUrl`, escaped with `p()`). Strings through `IL10N`, Dutch and English in
  `l10n/`; `npm run check:l10n-js` stays green.
  - **fails today**: e2e `a visitor without JavaScript is told and given a way on` (notice text, link href, skip link
    focus) and `a visitor with JavaScript sees no notice` (exactly one `#pq-main`, notice hidden).
  - unit `tests/Unit/Controller/PortalPageControllerTest.php::testThePlainUrlCarriesTheRouteAndTheSearch`.

## 2. The plain version (REQ-SHJ-002)

- [ ] 2.1 Route `portalPage#plain` at `GET /site/plain`, `PortalPageController::plain()` with `#[PublicPage]`,
  `#[NoCSRFRequired]`, `#[NoAdminRequired]` and the same `#[AnonRateLimit]` as `site()`, rendering
  `templates/site-plain.php` with `RENDER_AS_BLANK` and the same CSP as `site()`. Resolve the portal as `site()`
  does; read every page and menu through `CmsReader` with audience `anonymous`, never the session's.
  - **fails today**: `tests/Unit/Controller/PortalPageControllerPlainTest.php::testThePlainPageIsPublicAndRateLimited`,
    `testItReadsAsAnonymousEvenWithASession`, `testAnUnknownOrDraftRouteIs404`.
  - Gates route-auth and semantic-auth must pass.
- [ ] 2.2 Move the stylesheet list and the theme `<style>` lines out of `templates/site.php` into one shared partial
  `templates/parts/site-stylesheets.php`, included by both templates, so the plain page wears the same theme.
  - node test `tests/site-plain.spec.mjs` (new, wired into `check:specs` as `check:site-plain`):
    `both templates include the same stylesheet partial` and `the plain template contains no script element`.
- [ ] 2.3 `lib/Service/Cms/PlainMarkdown.php`: a converter for headings, paragraphs, ordered and unordered lists,
  links, emphasis and inline and fenced code; every other character escaped; link targets limited to `http`,
  `https`, `mailto` and relative paths (others are rendered as text).
  - **fails today**: `tests/Unit/Service/Cms/PlainMarkdownTest.php::testTheSubsetRenders`,
    `testScriptIsEscaped`, `testAJavascriptLinkLosesItsTarget`, `testRawHtmlIsEscaped`.
- [ ] 2.4 `lib/Service/Cms/PlainPageRenderer.php` builds the view model: title, summary, menu links into
  `/site/plain`, a markdown body through `PlainMarkdown`, and per grid widget (in grid order) the text of `markdown`,
  `nlHeading` (level clamped 1 to 6), `nlParagraph`, `nlList` and `nlLinkList` (read each widget's props from its
  component in `src/site/widgets/` first), the publication widgets from section 3, and for every other widget the
  "needs JavaScript" sentence with the widget's label from `src/lib/widgetLabels.js`'s table (copied into a PHP
  map, with a node test that the two agree) and a link to the full page.
  - **fails today**: `tests/Unit/Service/Cms/PlainPageRendererTest.php::testTextWidgetsRender`,
    `testAnInteractiveWidgetSaysItNeedsJavascript`, `testNoWidgetPlaceIsLeftEmpty`.
  - node test in `tests/site-plain.spec.mjs`: `every widget key the site registers has a plain rendering or a label`
    (reads `src/site/components/WidgetGrid.vue`'s map and `src/site/widgets/index.js`).
- [ ] 2.5 The plain page carries `<link rel="canonical">` to the `/site` address of the same route and the robots
  value `SiteHead` gives that route.
  - unit `PortalPageControllerPlainTest::testTheCanonicalIsTheSiteAddress`.
  - e2e `a text page reads the same without JavaScript`, `a part that needs JavaScript says so` and
    `a draft or a session never leaks` (the last with a signed-in resident's storage state).

## 3. Publications without JavaScript (REQ-SHJ-003, REQ-SHJ-004, REQ-SHJ-005)

- [ ] 3.1 `lib/Service/Cms/PlainPublicationReader.php`: `search(string $endpoint, string $query, int $page, int $pageSize): array{state: 'ok'|'unavailable', total: int, results: list<array{id: string, title: string, date: string, summary: string}>}`,
  `publication(string $endpoint, string $id): array{state: 'ok'|'not-found'|'unavailable', publication?: array, documents?: list<array{name: string, type: string, size: int, href: string}>}`.
  Calls go through `InstanceLoopback::request('GET', $path, ...)` with no cookie and no `Authorization` header and a
  five second timeout. Only a relative endpoint path is called; an absolute URL, opencatalogi disabled
  (`IAppManager::isEnabledForUser('opencatalogi')` false), a transport error or a status of 500 or above give
  `unavailable`; 404 and 403 on the by-id read give `not-found`. Field names follow
  `src/site/lib/federatedSearch.js::toResult()` and `src/site/lib/publicationDetail.js`; check them against
  opencatalogi's `FederationController::publications()`, `publication()` and `publicationAttachments()` (routes `/api/federation/publications`, `/{id}`, `/{id}/attachments`) on `development` before writing, and name them in
  the class docblock.
  - **fails today**: `tests/Unit/Service/Cms/PlainPublicationReaderTest.php::testTheCallCarriesNoCredentials`,
    `testAForeignEndpointIsNeverCalled`, `testAnAbsentAppIsUnavailableNotEmpty`, `testAServerErrorIsUnavailable`,
    `testMissingAndForbiddenAreBothNotFound`, `testFieldsAreReadTheWayTheBlockReadsThem`.
    Double `InstanceLoopback` with `environmentAwareDouble` against the real class signature.
- [ ] 3.2 The plain search on a `federatedSearch` widget: the GET form (labelled field `_search`, hidden `route`,
  submit button), the results, the total, and previous and next links with `_page`; `unavailable` renders the
  REQ-SHJ-005 sentence and never a zero total.
  - **fails today**: `tests/Unit/Service/Cms/PlainPublicationSearchTest.php::testTheFormSubmitsToThePlainRoute`,
    `testPageTwoOfTwentyFive`, `testUnavailableIsNotNothingFound`.
- [ ] 3.3 The plain detail on a `publicationDetail` widget with a trailing segment (resolve the parent route the way
  `src/site/App.vue::parentRoute()` does): title, summary, date, category and themes by name, documents as download
  links; `not-found` answers 404 with one body for both causes.
  - **fails today**: `tests/Unit/Service/Cms/PlainPublicationDetailTest.php::testThePublicationAndItsDocuments`,
    `testMissingAndWithheldAnswerTheSame404`.
- [ ] 3.4 CI: add `{"repo":"ConductionNL/opencatalogi","app":"opencatalogi","ref":"development"}` to
  `e2e-additional-apps` in `.github/workflows/code-quality.yml`, and seed one published publication titled
  "Woo-besluit afvalinzameling 2026" with one PDF in `tests/e2e/ci-seed.sh` through opencatalogi's own API. If
  opencatalogi cannot be installed in that job, stop and report it in the PR body; do not skip the tests below.
  - e2e `a search works with JavaScript off` and `a publication and its documents open without JavaScript`
    (follow the title, download the PDF, compare its bytes), and `missing and withheld look the same`.

## 4. Accessibility and live

- [ ] 4.1 The plain pages pass the same axe check as the site: add `/site/plain?route=/over-ons` and
  `/site/plain?route=/zoeken&_search=afval` to `tests/e2e/site-accessibility.spec.ts`. Run axe there with
  JavaScript on: the plain page carries no script (2.2 proves it), so its DOM is the one a visitor without
  JavaScript gets, and axe needs scripting to run.
- [ ] 4.2 Live check after merge on the dev instance, in a browser with JavaScript disabled: open a portal's home,
  follow the notice, search for a publication, open it and download a document. Record the addresses and what each
  page showed in the PR.

## 5. Verify and deliver

- [ ] 5.1 `TMPDIR` set to a sibling directory beside the clone. While building, run
  `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on the touched classes and `node --test` on the
  touched node tests. Judge PHPUnit by the `Tests:` line.
- [ ] 5.2 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:register`, `npm run check:specs` and `npm run build:site`, plus any other leg `code-quality.yml`
  requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 5.3 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages
  from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and
  say in the PR body that the number is arithmetic, not a local green.
- [ ] 5.4 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.6 then reads `yes` (build), and `production` only with a store release.
