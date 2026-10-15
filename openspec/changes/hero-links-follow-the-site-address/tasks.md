# Tasks: hero-links-follow-the-site-address

- [x] **T1**: `linkAddress()` in `src/site/lib/shellData.js`; `FooterColumns.vue` and `HeroBlock.vue` use it
  - node `tests/site-shell-blocks.spec.mjs` "a block link to a site route takes the site address; another link stays as authored", "the hero writes its popular links and actions in the site address"
- [x] **T2**: `authoredLink()` keeps a query; `NlPortalCounts.vue`, `NlFeaturedSubjects.vue` and `NlPublicDetail.vue` write their site links through it
  - node `tests/site-shell-blocks.spec.mjs` "a block link with a query keeps the query beside the route in the site address"
- [ ] **T3**: Live check on the rehearsal: no link on the Zuiddrecht site reads a bare `/route` under the app path
