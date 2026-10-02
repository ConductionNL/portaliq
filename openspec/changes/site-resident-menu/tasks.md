# Tasks: site-resident-menu

- [x] 1. `src/site/App.vue`: the header menus are the CMS header menu only (REQ-SRM-001). Verify: `tests/site-resident-menu.spec.mjs`.
- [x] 2. `src/site/lib/residentMenu.js` and `src/site/components/ResidentMenu.vue`, rendered by `AccountArea.vue` on `/mijn` pages when signed in (REQ-SRM-002). Verify: same spec.
- [x] 3. `src/site/components/BrandHeader.vue`: the "Mijn omgeving" link beside the name and sign-out (REQ-SRM-003). Verify: same spec.
- [x] 4. The phone button and the one-column layout below 768 px (REQ-SRM-004). Verify: same spec, screenshots at 390 px.
- [x] 5. English and Dutch strings in `src/shared/i18n`; the threads section reads "Gesprekken" (REQ-SRM-002). Verify: same spec.
