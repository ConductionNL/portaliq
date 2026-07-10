## 1. i18n bundle + loader

- [x] 1.1 Created `src/portal/i18n/en.json` with English source keys for
      every literal in `src/portal/App.jsx`.
- [x] 1.2 Created `src/portal/i18n/nl.json` with the current Dutch strings as
      translations of those same English keys.
- [x] 1.3 Added `src/portal/i18n/index.js` — `createTranslator(locale)`
      builds a bound `t(key, vars?)`; unknown/unsupported locale falls back
      to the `en` bundle at the loader level (`bundleFor()`), and
      `PortalOrganisationConfigService::normaliseLocale()` additionally
      normalises the resolved locale itself to `nl`/`en` server-side before
      it ever reaches the client. Interpolation covers both strings that
      need it: `Logged in as {subjectRef}` and `{field}?`.

## 2. Wire locale into RUNTIME_CONFIG

- [x] 2.1 `src/portal/main.jsx`: reads `RUNTIME_CONFIG.locale` (via
      `loadState`, not a literal `window.RUNTIME_CONFIG` — see
      `portal-white-label-runtime-config`), defaulting to `'nl'` in the
      dev-only fallback object.
- [x] 2.2 `lib/Controller/PortalPageController.php::resolveLocale()` reads
      the first `Accept-Language` tag; `PortalOrganisationConfigService::resolve()`
      now takes a `$locale` parameter, normalises it (`nl`/`en`, else `nl`),
      and includes it in every returned config shape (neutral default AND a
      resolved Organisation) — landed together with
      `portal-white-label-runtime-config` in this same apply pass, so no
      "if that change already landed" branching was needed.

## 3. Replace hard-coded literals

- [x] 3.1 `src/portal/App.jsx` — every literal from proposal.md now goes
      through `t('key')` (or `t('key', {vars})` for the two interpolated
      strings), using the `t` prop threaded down from `main.jsx`.
- [ ] 3.2 `window.prompt()` field collection in `createInCollection` (and the
      new `invokeEndpointAction`) still uses `window.prompt()` — its
      messages are now translated (`t('{field}?', {field})`,
      `t('New document')`), but the REPLACEMENT with an inline labelled form
      is `portal-spa-nl-design-system-styling`'s task (3.1 there) — doing it
      once, in that change, as originally coordinated, rather than twice.
- [x] 3.3 Verified: every i18n key in `en.json`/`App.jsx` is an English
      source string; `nl.json` carries the Dutch translations. No Dutch
      string is used as a key.

## 4. Tests + gates

- [x] 4.1 (adapted) PHPUnit `PortalPageControllerTest::testIndexPassesTheFirstAcceptLanguageTagToTheResolver`
      and `PortalOrganisationConfigServiceTest`'s locale cases prove the
      server-side resolution switches on `Accept-Language` and normalises
      correctly. A Playwright e2e loading `/portal` with different
      `Accept-Language` headers and asserting the rendered heading text
      differs is DEFERRED — needs a running instance; not run as part of
      this apply pass.
- [ ] 4.2 Run Hydra gates (spdx-headers, i18n-keys-english convention,
      spec-coverage) before push. — not run as part of this apply pass
      (process/review step); flag for the PR review stage.
