# Tasks: site dates in content language

- [x] 1. `App.vue`: `contentLocale`, `provide`, the page's `lang`.
- [x] 2. News list, news article, event list, catalogue read it for their dates.
- [x] 3. `tests/site-look/content-dates.spec.mjs` (runs under `check:site-look`, added by #1363).
- [x] 4. Live check on a development backend with an English browser. Done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main and portaliq development: the open-tilburg home page set to locale `nl` with a news list block and one published news item of 2 October 2026, opened by Chromium with locale en-US and `Accept-Language: en-US`. The page's `<article data-testid="site-page">` carries `lang="nl"` and the news row reads "2 oktober 2026", not "October 2, 2026"; the same in an nl-NL browser. The wilgenboom (learniq) example set of the scenario is not on the sandbox; the open-tilburg page stands in for it.
