# Tasks: language-switch-reaches-the-content

- [x] **T1**: `languageNav.js`: read `?lang=`, build one entry per portal locale
  - `node --test tests/language-nav.spec.mjs`
- [x] **T2**: `contentApi.js` sends `locale` on site, menus, pages, glossary and page reads
  - `node --test tests/language-nav.spec.mjs`
- [x] **T3**: `WidgetGrid` hands `nlLanguageNav` the portal's locales; `App.vue` supplies them and keeps `?lang=` on its links
  - `node --test tests/language-nav.spec.mjs`
