# Tasks: home-and-theme-landing-pages

Wave 2. Rows 6.20, 6.23 and the portaliq half of 6.28. Kind: code. Build rules:
`~/memcap-work/woo-build/LANE-RULES-BUILD.md`.

**Before starting**, read opencatalogi's `subjects-as-first-class-records` on `development`. If it is
not merged, sections 2 and 3 cannot be proven live; build section 1 and 4 first and stop there, and
say so in the PR body. Write in the PR body the exact keys the merged routes answer. A test marked
**fails today** must be run on `origin/development` first and seen red. New widgets follow
`site-nlds-widget-palette`: a meta, a loader, out of the site entry (`check:widget-registry`,
`scripts/check-site-chunks.js`), tokens only (`check:widget-tokens`).

## 1. The locked facet

- [ ] 1.1 `lockedFilters` on the block and in `buildRequestUrl()`; chips without remove; field left out of
  the facets and of `writeSearchState()` (REQ-HTL-001).
  - **fails today**: node test `tests/federated-search.spec.mjs` `a locked filter is always sent`,
    `a locked field is not offered as a facet`, `a locked filter is not written to the address`.

## 2. The subject page

- [ ] 2.1 Route `/onderwerp/{slug}` in the site router and a `SubjectLandingPage` component reading the
  subject route and mounting the search block locked to it; 404 through the not-found page when the
  read answers 404 or no public subject (REQ-HTL-002).
  - node test `tests/subject-landing.spec.mjs` (wire into `check:specs`): `the subject's image, title
    and description render`, `an unknown subject renders not found`.
  - Contract: the test fixture is the REQ-SUB-002 and REQ-SUB-003 response copied from opencatalogi's
    spec with a source line; opencatalogi's change tests the same keys on its side.
  - e2e `tests/e2e/subject-landing.spec.ts` on the dev rig: open `/onderwerp/{slug}` for a seeded
    public subject and see its publications only. Cite REQ-HTL-002.

## 3. Featured subjects

- [ ] 3.1 Widget `featuredSubjects` (label "Uitgelichte onderwerpen") with an author field for the count;
  filters rows on `featured === true` client-side as well, so an older opencatalogi that ignores the
  query parameter shows nothing rather than everything (REQ-HTL-003).
  - **fails today** (widget absent): node test `tests/widget-registry.spec.mjs`
    `featuredSubjects is registered with a meta`, and `tests/subject-landing.spec.mjs`
    `only featured rows are listed, in featuredOrder`.

## 4. Live counts

- [ ] 4.1 Widget `portalCounts` (label "Wat we publiceren, in aantallen"), author picks category, subject
  or year. One search request with `_limit=0` and the facet, made with `credentials: 'omit'`; each
  bucket links to the search page with the filter set; a missing bucket is left out (REQ-HTL-004).
  - **fails today** (widget absent): node test `tests/portal-counts.spec.mjs` (wire into
    `check:specs`): `the request omits credentials`, `each count links to a filtered search`,
    `a missing count is not shown as zero`.
  - e2e `tests/e2e/portal-counts.spec.ts`: signed in as an officer with a draft in a category, the
    count equals the anonymous count. Cite REQ-HTL-004.

## 5. Live

- [ ] 5.1 Live check after merge on the dev instance: a home page with both widgets and one subject
  page. Record a screenshot of each and the count a signed-in officer sees against the anonymous one.

## 6. Verify and deliver

- [ ] 6.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 6.2 While building, run `node --test` on the touched node tests.
- [ ] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`,
  `npm run check:specs` and `npm run build:site` (the entry budget), plus any other leg
  `code-quality.yml` requires. Then hydra's `scripts/run-hydra-gates.sh --base origin/development`;
  count the gates that ran.
- [ ] 6.4 Project coverage of the added statements as LANE-RULES-BUILD says.
- [ ] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.20 and 6.23 then read `yes` (build); 6.28 when opencatalogi's half is
  merged too. `production` only with a store release.
