# Tasks: media-usage-count

- [x] **T01**: media schema 0.2.0 `usedOn`, register 0.99.0; PortaliqRegisterConfigTest (REQ-MU-001)
- [x] **T02**: `MediaUsage` count and refresh; `MediaUsageJob` hourly in `appinfo/info.xml`; PHPUnit (REQ-MU-001)
- [x] **T03**: Media list column and pill; `tests/media-usage-pill.spec.mjs` (REQ-MU-001)
- [x] **T04**: Live check after `occ upgrade`
  - Live pass 2026-11-17 on a fresh install (NC 34.0.6, openregister main, media schema 0.2.0): with one page referring to `media:<id>`, `MediaUsageJob` wrote `usedOn` 1 on that item. An unused item stayed `null` and so never reached the "Nergens gebruikt" (0) filter: OpenRegister reads an uncounted item as `usedOn: null`, which the recount took for a count of 0. Fixed in `MediaUsage::recount()` with `MediaUsageTest::testAnUncountedUnusedItemGetsZero`; after the fix the unused item reads 0 and the 0 filter lists it.
