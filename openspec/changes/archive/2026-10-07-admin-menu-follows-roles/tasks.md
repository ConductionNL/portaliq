# Tasks: admin-menu-follows-roles

- [x] **T1**: `AdminMenuAccess` computes the four flags; `DashboardController::page` provides them as initial state `access`.
  - `AdminMenuAccessTest` (4 tests), `DashboardControllerTest::testThePageHandsTheAppTheAccessFlags`
- [x] **T2**: The manifest's menu entries name their flag; the app puts the flags in `manifest.runtime.access`; a router guard sends a hidden page's address to the dashboard.
  - `node --test tests/admin-menu-access.spec.mjs` (6 tests, uses CnAppNav's own predicate evaluator on the real manifest)
  - Live: as `po-leerkracht-09` the menu shows Dashboard, News and Documentation only
