# Proposal: language-switch-reaches-the-content

## Why

The language switch (`nlLanguageNav`, design D1 row 52) could never render. It shows only with more than one locale, and its docblock says the shell hands the locales down. Nothing did: `WidgetGrid.propsFor()` had no branch for it, so `locales` kept its empty default. The content API already accepted `locale` and answered the portal's `locales` on `/site`, but `contentApi.js` never sent one.

## What changes

- `src/site/lib/languageNav.js`: the chosen language travels on the address as `?lang=`. Each portal locale becomes a link to the same page in that language, named in itself ("Nederlands", "English").
- `App.vue` reads `?lang=` once, sends it as `locale` on the site, menus, glossary and page reads, and builds the switch from the `/site` answer (`locales`, `locale`). It binds the switch data on every grid through `gridContext`.
- `WidgetGrid` takes a `languages` prop and hands `nlLanguageNav` its `locales` and `current` after the authored props. A placement can rename the landmark but cannot add a language.
- Links inside the site keep `?lang=`, so the next page opens in the chosen language.

The content API checks the locale against the portal's own set and falls back to the first entry (`ContentController::locale()`), so no server change is needed.
