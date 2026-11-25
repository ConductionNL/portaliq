# Tasks: site-location-map

- [x] **T01**: `src/site/widgets/nlLocationMap/` (meta, logic, block), registered in loaders, metas and SITE_COMPOSITIONS; `tests/site-location-map.spec.mjs` (REQ-SLM-001)
- [x] **T02**: Zuiddrecht example page `/contact/locaties`, linked from Contact (REQ-SLM-001)
- [x] **T03**: Live check against LocatiesKaart and an axe run
  - Run live 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (PostgreSQL 16, OpenRegister main, thematiq and portaliq development), `occ portaliq:example-site:install zuiddrecht`, page `/contact/locaties` in the Zuiddrecht theme. axe-core (wcag2a/2aa/21a/21aa/22aa): one serious rule, `target-size` on the four place links (Route, Afspraak maken, Wat kunt u brengen), fixed here (24px link boxes); after it 0 serious or critical. Next to the board: the chips, the numbered markers matching the numbered list, the address and opening lines, the links and the "new window" note match.
- [ ] **T04**: Close the gaps the live check found next to LocatiesKaart: the sketch draws only its ground (the board has streets, water, a park and the caption "Kaart: schets, geen echte kaartgegevens"); the markers carry no name label; a place is not a bordered card with an open-now line ("Nu open tot 17:00 uur"), and Route is a plain link where the board draws an outlined button with a route icon.
