# Woo build queue for this repository

12 OpenSpec changes in this repository close gaps in the Woo capability programme. Each has a change folder under `openspec/changes/` and an issue titled `[OpenSpec] <change-name>` that the OpenSpec workflow keeps in step with the spec.

## How to pick up a change

1. Take the first change below whose dependencies are all merged on `development`. A dependency in another repository is linked to its issue there; check that issue's linked PR is merged.
2. Inside a wave, the order below is the order to build. Statutory rows come first.
3. Read `openspec/woo-build-rules.md` before the first command, then the change's `proposal.md`, its specs and its `tasks.md`.
4. The decisions the specs cite (D1 to D13) are in `openspec/woo-decisions.md`. A spec never contradicts one. If a task seems to, stop and say so in the issue.
5. Work on the branch the issue names, open one PR with `--base development`, and close the issue through the PR.

Two things need a person, not an agent: settling the Woo refusal grounds against the law (dossiq `woo-refusal-grounds-list`, task 1, blocks seeding), and the screen-reader pass for row 15.5.

## Wave 1

| change | rows | depends on |
|---|---|---|
| [portaliq/portal-identity-from-the-admin](https://github.com/ConductionNL/portaliq/issues/1214) | 6.22, 15.8 | nothing |
| [portaliq/portal-traffic-zero-result-searches](https://github.com/ConductionNL/portaliq/issues/1215) | 16.3 | nothing |
| [portaliq/search-sort-by-relevance](https://github.com/ConductionNL/portaliq/issues/1216) | 6.14, 6.17 | nothing |
| [portaliq/site-accessibility-statement](https://github.com/ConductionNL/portaliq/issues/1217) | 6.7, 15.2 | nothing |
| [portaliq/site-honest-without-javascript](https://github.com/ConductionNL/portaliq/issues/1248) | 6.6 | nothing |
| [portaliq/site-nlds-widget-palette](https://github.com/ConductionNL/portaliq/issues/1218) | 6.13 | nothing |

## Wave 2

| change | rows | depends on |
|---|---|---|
| [portaliq/home-and-theme-landing-pages](https://github.com/ConductionNL/portaliq/issues/1219) | 6.20, 6.23 (and the portaliq half of 6.28) | [opencatalogi/subjects-as-first-class-records](https://github.com/ConductionNL/opencatalogi/issues/1764) |
| [portaliq/portal-federated-search](https://github.com/ConductionNL/portaliq/issues/1224) | 6.2 | nothing |
| [portaliq/publication-detail-page-complete](https://github.com/ConductionNL/portaliq/issues/1220) | 6.4, 6.19, 6.35, 6.36, 7.20 | [opencatalogi/publication-detail-for-the-portal](https://github.com/ConductionNL/opencatalogi/issues/1761) |
| [portaliq/publication-error-reports-and-withheld-notices](https://github.com/ConductionNL/portaliq/issues/1221) | 6.15, 6.16 | [opencatalogi/publication-detail-for-the-portal](https://github.com/ConductionNL/opencatalogi/issues/1761) |
| [portaliq/search-filter-by-kind](https://github.com/ConductionNL/portaliq/issues/1222) | 6.30 | [opencatalogi/subjects-as-first-class-records](https://github.com/ConductionNL/opencatalogi/issues/1764) |

## Wave 3

| change | rows | depends on |
|---|---|---|
| [portaliq/woo-intake-delivers-to-dossiq](https://github.com/ConductionNL/portaliq/issues/1223) | supports 7.1, 7.2 | [dossiq/woo-request-takes-over-from-opencatalogi](https://github.com/ConductionNL/dossiq/issues/3289) |
