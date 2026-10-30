# Tasks: site-links-follow-the-site-address

- [x] **T1**: `src/site/lib/shellData.js` `isSiteRoute()` and `menusWithAddresses()`; `App.vue` gives the header menus and the menu block addresses through `hrefForRoute`; `FooterColumns.vue` writes site routes through its `addressOf` prop
  - node `tests/site-shell-blocks.spec.mjs`: four tests on the helper, the domain case and the rendered menu and footer
