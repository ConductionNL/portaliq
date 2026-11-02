# Tasks: portal-spa-i18n-locale-support

As built (checked box by box against the code on 14 Oct 2026). The change was
first built in the React portal (`src/portal/`), which has since been
removed; the same capability lives in the site shell, and each box names
where it is now.

## 1. i18n bundle + loader

- [x] 1.1 English source keys: `src/shared/i18n/en.json` (the key is the English text).
- [x] 1.2 Dutch translations of those same keys: `src/shared/i18n/nl.json`.
- [x] 1.3 `src/shared/i18n/index.js`: `createTranslator(locale)` binds `t(key, vars?)`; `bundleFor()` falls back to the English bundle for an unknown locale, and an unknown key renders as its English source text. Interpolation replaces `{name}` placeholders.

## 2. Wire the locale in

- [x] 2.1 The site reads the resolved locale from its runtime configuration (`SiteShell` provides it; the shell builds its translator with `createTranslator`).
- [x] 2.2 `lib/Service/SiteShell.php` resolves the visitor's locale from the portal's declared locales and the first `Accept-Language` tag; `PortalOrganisationConfigService::normaliseLocale()` keeps a server-side locale to `nl` or `en` (`nl` when unsupported).

## 3. No hard-coded literals

- [x] 3.1 The site's own words go through `t('English key')`; the React `App.jsx` they were first written for is gone.
- [x] 3.2 Retired with the React portal: there is no `window.prompt()` left in `src/` (`grep -rn window.prompt src/` finds nothing).
- [x] 3.3 Every key in `src/shared/i18n/en.json` is an English source string and `nl.json` carries the Dutch; no Dutch text is used as a key (`npm run check:l10n-js` guards the site's keys).

## 4. Tests + gates

- [x] 4.1 PHPUnit `PortalPageControllerTest::testSiteAlwaysPassesANonEmptyLocale`, `::testSiteLocaleFollowsThePortalsDeclaredLocales`, and `PortalOrganisationConfigServiceTest::testLocaleDefaultsToNlWhenAbsent`, `::testSupportedLocaleIsHonoured`, `::testUnsupportedLocaleFallsBackToNl`. The Playwright check with different `Accept-Language` headers is a live check (decision 139).
- [x] 4.2 Hydra gates run on the archive PR (local run and CI), exit code in its body.
