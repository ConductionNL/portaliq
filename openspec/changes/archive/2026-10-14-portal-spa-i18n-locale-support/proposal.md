---
kind: code
---

## Why

The public portal SPA (`src/portal/App.jsx`) is the reference implementation of
the supplier-portal spec's **"portal MUST be organisation-agnostic and
white-label"** requirement
(`openspec/changes/supplier-portal/specs/supplier-portal/spec.md:68-78`), which
already covers per-tenant name/logo/theme. It says nothing about locale, and
the code shows why that gap matters: every user-visible string in the shell is
a hard-coded Dutch literal, with **zero** use of `@nextcloud/l10n` (a declared
`package.json` dependency, never imported anywhere under `src/portal/`):

- `src/portal/App.jsx:146` `'Uitloggen'`
- `src/portal/App.jsx:155-156` `'Welkom'`, `'Log in om uw gegevens te bekijken.'`
- `src/portal/App.jsx:160` `'Inloggen met eHerkenning'` / `'Inloggen met DigiD'`
- `src/portal/App.jsx:163` `'Dev-login (test)'`
- `src/portal/App.jsx:171` `'Mijn overzicht'`
- `src/portal/App.jsx:173` `'Ingelogd als'`
- `src/portal/App.jsx:181` `'Nog geen bijdragen om weer te geven.'`
- `src/portal/App.jsx:198` `'Geen berichten.'` / `'Geen items.'`
- `src/portal/App.jsx:81` `window.prompt(\`${field}?\`, 'Nieuw document')` — even
  the create-action prompt default value is a Dutch literal
- `src/portal/App.jsx:203` `'(geen onderwerp)'`

`window.RUNTIME_CONFIG` (`src/portal/main.jsx:14-20`) has no `locale` field
either, so there is no per-tenant or per-visitor signal to switch on even if
the strings were externalised. Meanwhile `l10n/en.json` / `l10n/nl.json` exist
and are wired for the **internal Vue admin bundle** (`src/main.js` etc.) — the
public-facing SPA that external suppliers/clients across every municipality
tenant actually see has no i18n path at all. This directly contradicts the
company's translation-key rule
(`hydra/openspec/architecture/adr-004-frontend.md`: "ALL user-visible strings
via `t(appName, 'text')`. NO hardcoded strings. Translation keys MUST be
English") — the rule targets the Vue surface literally, but the underlying
principle (English-source keys, externalised strings) is violated wholesale
here, and a portal serving Dutch municipalities cannot assume every supplier
or client user reads Dutch (e.g. an English-only accessibility need, or a
future non-NL tenant).

## What Changes

- Add `@nextcloud/l10n`'s framework-agnostic `getLanguage()`/`translate()`
  (or a minimal local `t()` shim using the same JSON translation-bundle shape,
  since `loadTranslations()` targets Nextcloud's asset pipeline and this bundle
  is served standalone per `webpack.portal.js`) to `src/portal/`.
  All literals listed above move into a `src/portal/i18n/en.json` source
  bundle with English keys (per company rule), plus an `nl.json` translation
  bundle wired to `l10n/`-equivalent tooling for the portal build.
- `window.RUNTIME_CONFIG` gains a server-resolved `locale` field (default
  `'nl'` — the current de-facto behaviour — set from the visitor's
  `Accept-Language` header or the tenant Organisation's configured default
  locale, whichever the org resolution work in
  `portal-white-label-runtime-config` lands first).
- Replace `window.prompt()`-based field collection
  (`src/portal/App.jsx:78-97`, `createInCollection`) with a translated inline
  form (also removes the untranslatable native-prompt UX — see the companion
  a11y change `portal-spa-nl-design-system-styling` for the broader dialog
  replacement).
- No behavioural change to auth, session, or contribution logic — this is a
  pure string-externalisation + locale-plumbing change.

## Capabilities

### Modified Capabilities
- `supplier-portal`: the white-label requirement gains a locale dimension —
  the shell SHALL render in the resolved locale, not a hard-coded language.

## Impact

- `src/portal/App.jsx` — replace all literals with `t()` calls.
- `src/portal/main.jsx` — read `RUNTIME_CONFIG.locale`, initialise the i18n
  bundle before mount.
- New: `src/portal/i18n/en.json`, `src/portal/i18n/nl.json`.
- `lib/Controller/PortalPageController.php` — coordinate with
  `portal-white-label-runtime-config`'s `RUNTIME_CONFIG` resolution to add
  `locale` (or resolve independently via `Accept-Language` if that change has
  not landed first).
