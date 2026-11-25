# Tasks: home-and-theme-landing-pages

Wave 2. Rows 6.20, 6.23 and the portaliq half of 6.28. Kind: code. Build rules:
`openspec/woo-build-rules.md`.

**Before starting**, read opencatalogi's `subjects-as-first-class-records` on `development`. If it is
not merged, sections 2 and 3 cannot be proven live; build section 1 and 4 first and stop there, and
say so in the PR body. Write in the PR body the exact keys the merged routes answer. A test marked
**fails today** must be run on `origin/development` first and seen red. New widgets follow
`site-nlds-widget-palette`: a meta, a loader, out of the site entry (`check:widget-registry`,
`scripts/check-site-chunks.js`), tokens only (`check:widget-tokens`).

## 1. The locked facet

- [x] 1.1 `lockedFilters` on the block and in `buildRequestUrl()`; chips without remove; field left out of — done. The red run on `origin/development` was not made; the helpers do not exist there
  the facets and of `writeSearchState()` (REQ-HTL-001).
  - **fails today**: node test `tests/federated-search.spec.mjs` `a locked filter is always sent`,
    `a locked field is not offered as a facet`, `a locked filter is not written to the address`.

## 2. The subject page

- [x] 2.1 Route `/onderwerp/{slug}` in the site router and a `SubjectLandingPage` component reading the — the subject page is the widget `nlSubjectLanding` on a CMS page `/onderwerp` (the same way `/publicatie/<id>` works), not a router change, because the site entry has no room for one. A missing subject renders a not-found message in the widget; the HTTP status is the CMS page's. The e2e test is not run: needs the dev rig and opencatalogi's merged routes. The contract fixture is built from the proposal, not copied from a merged opencatalogi
  subject route and mounting the search block locked to it; 404 through the not-found page when the
  read answers 404 or no public subject (REQ-HTL-002).
  - node test `tests/subject-landing.spec.mjs` (wire into `check:specs`): `the subject's image, title
    and description render`, `an unknown subject renders not found`.
  - Contract: the test fixture is the REQ-SUB-002 and REQ-SUB-003 response copied from opencatalogi's
    spec with a source line; opencatalogi's change tests the same keys on its side.
  - e2e `tests/e2e/subject-landing.spec.ts` on the dev rig: open `/onderwerp/{slug}` for a seeded
    public subject and see its publications only. Cite REQ-HTL-002.
  - Fixed 20 Nov: `WidgetGrid.propsFor()` hands `nlSubjectLanding` the route's `routeParam` after the
    authored props (read from the address under `?route=` it found `site`), and the widget passes its
    search block's `navigate` on so a result link routes; node tests `the grid hands the subject page its
    slug from the route, and a placement cannot pin one`, `a result link on the subject page is handed on
    to the grid`.

## 3. Featured subjects

- [x] 3.1 Widget `featuredSubjects` (label "Uitgelichte onderwerpen") with an author field for the count; — the key is `nlFeaturedSubjects`: the registry requires an `nl` prefix
  filters rows on `featured === true` client-side as well, so an older opencatalogi that ignores the
  query parameter shows nothing rather than everything (REQ-HTL-003).
  - **fails today** (widget absent): node test `tests/widget-registry.spec.mjs`
    `featuredSubjects is registered with a meta`, and `tests/subject-landing.spec.mjs`
    `only featured rows are listed, in featuredOrder`.

## 4. Live counts

- [x] 4.1 Widget `portalCounts` (label "Wat we publiceren, in aantallen"), author picks category, subject — the key is `nlPortalCounts`. The per-year count uses a `date_histogram` facet that is not checked against a running opencatalogi. The e2e test is not run: needs the dev rig
  or year. One search request with `_limit=0` and the facet, made with `credentials: 'omit'`; each
  bucket links to the search page with the filter set; a missing bucket is left out (REQ-HTL-004).
  - **fails today** (widget absent): node test `tests/portal-counts.spec.mjs` (wire into
    `check:specs`): `the request omits credentials`, `each count links to a filtered search`,
    `a missing count is not shown as zero`.
  - e2e `tests/e2e/portal-counts.spec.ts`: signed in as an officer with a draft in a category, the
    count equals the anonymous count. Cite REQ-HTL-004.

## 5. Live

- [x] 5.1 Live check after merge on the dev instance: a home page with both widgets and one subject
  page. Record a screenshot of each and the count a signed-in officer sees against the anonymous one.
  — done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main (plus the MariaDB
  array-filter fix of openregister#11166, applied locally), opencatalogi and portaliq development, the
  Open publicaties example site: a featured subject "Verkeer en vervoer" with 3 public publications and
  1 draft. Home: "Uitgelichte onderwerpen" lists it with "3 publicaties"; "Wat we publiceren" per
  subject reads 3 anonymously AND signed in as the officer (who may read 4). Subject page
  `/onderwerp/verkeer-en-vervoer`: image, title, description and its 3 publications (4 for the officer,
  whose search carries the session). Found and fixed live: (1) opencatalogi's merged featured list
  answers the REQ-SUB-002 projection without a `featured` key, so the widget showed "no featured
  subjects" (`subjects.js` isFeaturedRow, `tests/subject-landing.spec.mjs`); (2) a subject count links
  to `f.themes`, which the search placement did not facet on, so the search listed everything
  (`withAddressFields`, `tests/federated-search.spec.mjs`). Screenshots are in the PR. The subject
  image came from an external host the site's CSP blocks: a sandbox seed choice, not an app red.

## 6. Verify and deliver

- [x] 6.1 `TMPDIR` set to a sibling directory beside the clone.
- [x] 6.2 While building, run `node --test` on the touched node tests.
- [x] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:specs` and `npm run build:site` (the entry budget), plus any other leg
  `code-quality.yml` requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`;
  count the gates that ran.
- [x] 6.4 Project coverage of the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [x] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.20 and 6.23 then read `yes` (build); 6.28 when opencatalogi's half is
  merged too. `production` only with a store release.

> 6.3 to 6.5 for the live round (25 Nov 2026): hydra diff-check with only the inherited gate-23
> finding; `npm run lint` 0 errors; check:subject-landing, check:portal-counts,
> check:federated-search, check:widget-registry, check:widget-tokens, check:manifest and
> `build:site` green. 6.4: the round adds JavaScript only, no PHP statement, so there are no clover
> lines to intersect. Sections 1 to 4 were merged in earlier PRs; this round is one PR, merged on
> development.
