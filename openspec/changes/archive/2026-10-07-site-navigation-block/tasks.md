# Tasks: site-navigation-block

- [x] **T1**: `navigationGroups`, `hasNavigationBlock`, `sideMenuOf` (`src/site/lib/siteNavigation.js`).
  - `node --test tests/site-navigation.spec.mjs` (groups, signed out, the placement rule)
- [x] **T2**: `SiteNavigationBlock.vue`, public in `WidgetGrid`, with the shell's data in `propsFor`.
  - `tests/site-navigation.spec.mjs` (landmark, headed groups, one current page, the phone button)
- [x] **T3**: The side region renders left of the content on CMS pages (not on `/mijn`, which has the resident menu of #1086); the header leaves its menu out.
  - `tests/site-navigation.spec.mjs` (header without menu keeps the account controls); `tests/site-shell-blocks.spec.mjs` and `tests/site-regions.spec.mjs` unchanged
- [x] **T4**: The "Menu" string in English and Dutch; `docs/operations/side-menu.md`.
- [x] **T5**: The `wilgenboom` portal on the primary-school instance carries the block in `regions.aside`.
  - Live (before merging #1086): Fatima Hulstkamp's pages showed the side menu, the header had no menu, the phone width showed the button
