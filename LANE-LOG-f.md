# Lane f-billing, part 2 (2026-09-28): portaliq news-item-translation
- Branch feat/news-item-translation (cut --no-track from origin/development 5776a4b), commits d345579..2ecdf86.
- PR https://github.com/ConductionNL/portaliq/pull/837 (not merged).
- Red first: NewsFeedReaderTest, NewsGuardianControllerTest, PortaliqRegisterConfigTest, tests/news-item-translation.spec.mjs.
- check:strict: 0 on lint/phpcs/phpmd/psalm/phpstan; PHPUnit 29 errors = the Class "OC" not found baseline, none touched. npm lint/check:specs/l10n-js/manifest/register 0; schema-l10n 11 = baseline. Gates diff EXIT 0 (57/57).
- npm ci fails on dev lockfile (pinia 4.0.3 missing): npm install --no-package-lock.
- opsx-verify headless: clean, 1 suggestion. DONE.

# Lane f-tails, part 2 (2026-09-28): portaliq news-title-and-newsletter-translation
- #837 merged (f5a0e1c). Branch feat/news-title-and-newsletter-translation, --no-track from origin/development.
- Commits d5eb7e5..fa5e1b8. Red first: NewsFeedReaderTest (4), NewsGuardianControllerTest (1), PortaliqRegisterConfigTest pin, tests/news-title-and-newsletter-translation.spec.mjs (5 of 7).
- Register 0.37.1, newsItem 0.2.1, mock 1.0.5. lint 0, check:specs 0, schema-l10n 11 = baseline.
- strict: static 0, PHPUnit 29 = class-not-found baseline (same set); phpmd NewsFeedReader complexity fixed via NewsRowSource (0e24e74). npm lint/specs 0, schema-l10n 11 baseline. Gates diff exit 2: gate 53 env ESM crash, gate 112 scaffold collection (inherited).
- PR https://github.com/ConductionNL/portaliq/pull/842 (not merged). opsx-verify headless: clean after adding NewsRowSourceTest (02457f6). DONE.

# Lane f-tails, part 3 (2026-09-28): portaliq e2e-drop-first-upload-exclusion
- Branch fix/e2e-drop-first-upload-exclusion (--no-track from origin/development), commit 5a28f1e: 33 lines deleted from tests/e2e/playwright.config.ts.
- eslint 0, prettier 0, npm run lint 0. No Playwright run possible here (said in PR body).
- PR https://github.com/ConductionNL/portaliq/pull/843 (not merged). DONE.
