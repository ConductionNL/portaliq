# Tasks: search-filter-by-kind

Wave 2. Row 6.30. Decision D11. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Before starting**, read on opencatalogi `development` whether `subjects-as-first-class-records`
REQ-SUB-004 is merged, and on which endpoint (`/api/search`, `/api/federation/publications`, or both)
`resultType` is answered. If the endpoint this block calls does not answer it, stop and say so; that is
opencatalogi's half. Confirm `publication-detail-page-complete` and `home-and-theme-landing-pages` are
merged in portaliq, or link to their routes only once they are. A test marked **fails today** must be
run on `origin/development` first and seen red.

## 1. The filter

- [x] 1.1 `facetFieldsOf()` and `toBuckets()` read the `resultType` facet; the block offers "Soort" only
  when it is present; `buildRequestUrl()` and `writeSearchState()` carry the choice (REQ-SFK-001).
  - **fails today**: node test `tests/federated-search.spec.mjs` `a chosen kind is sent and written to
    the address`, `no kind sends no resultType`, `no resultType facet offers no filter`.
  - Contract: the facet fixture is REQ-SUB-004's response shape copied with a source line;
    opencatalogi tests the same keys on its side.

## 2. The rendering

- [x] 2.1 `toResult()` maps each kind to its link and its secondary line (REQ-SFK-002).
  - **fails today**: node test `a document hit links to its page and names its publication`, `a
    subject hit links to its landing page with its count`, `an unknown kind renders as a publication`.
- [ ] 2.2 e2e `tests/e2e/site-federated-search.spec.ts` gains: search a seeded term, choose
  "Onderwerp", open the subject. Cite REQ-SFK-001 and REQ-SFK-002. — not run: needs a live instance and an opencatalogi that answers `resultType`.

## 3. Live and deliver

- [ ] 3.1 Live check after merge on the dev instance: one search filtered to each kind; record the counts. — not run: needs a live instance.
- [ ] 3.2 (run once for the whole branch, not per change) `TMPDIR` set to a sibling directory beside the clone. Before push, once:
  `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`, `npm run format`,
  `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run check:manifest`, `npm run check:specs`
  and `npm run build:site`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran. Project coverage of
  the added statements: no coverage driver runs locally, so take the base percentages from the last green push run on `development`, intersect its clover uncovered lines with the lines you add, and say in the PR body that the number is arithmetic, not a local green.
- [ ] 3.3 (not run: this branch carries several changes and opens no PR) One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 6.30 then reads `yes` (build), and `production` only with a store release.
