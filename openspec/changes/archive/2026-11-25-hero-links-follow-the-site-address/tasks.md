# Tasks: hero-links-follow-the-site-address

- [x] **T1**: `linkAddress()` in `src/site/lib/shellData.js`; `FooterColumns.vue` and `HeroBlock.vue` use it
  - node `tests/site-shell-blocks.spec.mjs` "a block link to a site route takes the site address; another link stays as authored", "the hero writes its popular links and actions in the site address"
- [x] **T2**: `authoredLink()` keeps a query; `NlPortalCounts.vue`, `NlFeaturedSubjects.vue` and `NlPublicDetail.vue` write their site links through it
  - node `tests/site-shell-blocks.spec.mjs` "a block link with a query keeps the query beside the route in the site address"
- [x] **T3**: Live check on the rehearsal: no link on the Zuiddrecht site reads a bare `/route` under the app path
  - Done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main, opencatalogi, dossiq, pipelinq and portaliq development and the Zuiddrecht example site: a crawler followed every `route=` link of the site under `/index.php/apps/portaliq/site?portal=zuiddrecht`, signed out (59 pages) and signed in as Sanne de Vries (80 pages), and listed every `href` starting with `/` that is not `/index.php` or `/apps/`. Found and fixed: the location map on `/contact/locaties` wrote its place links as authored ("Afspraak maken" -> `/afspraak-maken`, "Wat kunt u brengen" -> `/afval/afvalbrengstation`); `NlLocationMap.vue` now writes them through `linkAddress()` like the hero and the footer (`tests/site-location-map.spec.mjs`). After the fix the crawl finds no bare link.
