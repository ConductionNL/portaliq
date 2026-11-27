# Tasks: dashboard-figures-across-portals

- [x] **T01**: `PortalOverview` traffic and availability; PHPUnit (REQ-DFP-001)
- [x] **T02**: `PortalOverviewController` + routes, admin only; PHPUnit (REQ-DFP-001)
- [x] **T03**: Dashboard manifest: KPIs, availability card, traffic card, most viewed pages; `tests/dashboard-site-wide-figures.spec.mjs` (REQ-DFP-001)
- [x] **T04**: Live check against PtDashboard: done 25 Nov 2026 on a sandbox Nextcloud 34.0.6 (MariaDB, php -S) with traffic imported per portal through `occ portaliq:traffic:import-log`. The dashboard shows "Bezoekers, laatste 7 dagen" for all portals together, the availability card per portal, the traffic card (page views, sessions, visitors, engaged share) and the most viewed pages of several portals, most viewed first; a non-admin gets 403 from `/api/overview/traffic`. The board's own layout deviations (compdiff pt2) are the pixel pass, not this task.
- [x] **T05**: Found in the T04 check: a roll-up portal (`traffic.rollupOf`) was folded in as well, so its members' visits counted twice. `PortalOverview::traffic()` now leaves roll-ups out; `PortalOverviewTest::testARollupPortalIsNotCountedAgain` (red on the old code).
- [x] **T06**: Found in a second T04 check (25 Nov 2026, sandbox NC 34.0.6 + MariaDB, two portals with 15 and 9 visitors: KPI 24, pages ranked across both): the "Verkeer, laatste 7 dagen" and "Mijn Zuiddrecht nu" stats-block cards drew no title, because nc-vue draws card widgets headerless by default; their layout cells now set `showTitle: true`; `tests/dashboard-site-wide-figures.spec.mjs`.
