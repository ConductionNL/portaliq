# Tasks: woo-inbox-notices

- [x] 1. The change notice is written in the portal's language only (REQ-NAP-010). Verify: `tests/Unit/Listener/PortalRecordChangeListenerTest.php`.
- [x] 2. The portal inbox gives the badge the unread count of its loaded rows (REQ-NAP-011). Verify: `tests/inbox-unread.spec.mjs` (`npm run check:inbox-unread`), `tests/site-inbox-pages.spec.mjs`.
- [x] 3. e2e J6 asserts one match notice per new publication and no generic notice for the saved search, and an any-hour variant with an immediate search. Verify: `tests/e2e/woo-journey.spec.ts`.
- [x] 4. The site inbox leaves out the address that leads where "Open" leads, with its lead-in (REQ-NAP-012). Verify: `tests/inbox-open-link.spec.mjs` (`npm run check:inbox-open-link`).
