# Tasks: life-domain-theme-pages

- [ ] **T01**: `lib/Settings/portaliq_register.json`: `portal.themes` (list of `{slug, title, intro, productsLabel}`), portal 0.13.0; import and grep for `PARTIAL IMPORT` (REQ-LDT-001)
- [ ] **T02**: Contribution normaliser keeps `theme` on collections and actions when the portal declares it, and `kind: products` with `validUntilField` and `metaFields`; PHPUnit keep and drop (REQ-LDT-001)
- [ ] **T03**: `src/site/pages/e/ThemePage.vue` per the ThemaOverzicht board: tasks, actions and products blocks; empty text; route `/mijn/thema/{slug}` (REQ-LDT-001)
- [ ] **T04**: Resident menu group "Thema's" listing themes with content (`src/site/components/SiteMenu.vue`) (REQ-LDT-001)
- [ ] **T05**: Products block per the ThemaOverzicht board: count line, title, computed tag (Geldig, Verlopen, Gaat in op), meta line, "Geldig tot en met", at most three rows, row update actions through the existing action form; normaliser keeps `titleField`, `validFromField`, `validUntilField`, `metaFields`, `countLabel` on a `kind: products` collection; PHPUnit keep and drop; node test for the tag (REQ-LDT-003, REQ-LDT-004). Decided by decision 105 (8 Oct).
- [ ] **T05b**: `/mijn/thema/{slug}/producten`, the full list per theme, expired last (REQ-LDT-004)
- [ ] **T06**: `when` conditions on theme actions evaluated against the resident's products (REQ-LDT-002)
- [ ] **T07**: node test `tests/life-domain-themes.spec.mjs` for gathering, empty themes, `when` and the update
- [ ] **T08**: Zuiddrecht example site declares Parkeren; ask dossiq in its tracker to tag its permit collection and actions
- [ ] **T09**: Live check against the ThemaOverzicht board; screenshots in the build PR
