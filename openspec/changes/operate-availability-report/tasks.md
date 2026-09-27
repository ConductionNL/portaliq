# Tasks: operate-availability-report

## Measuring

- [ ] **T01**: `portalAvailabilityDaily` and `portalAvailabilityOutage` schemas with `portal`, register version bump (REQ-OAR-001). Verification: register import test.
- [ ] **T02**: `lib/BackgroundJob/AvailabilityProbeJob.php` registered in `appinfo/info.xml`: check, classify, update the daily record and the open outage (REQ-OAR-001). Verification: `AvailabilityProbeJobTest::testSiteErrorIsDown`, `::testDegradedHealthIsDegraded`, `::testTimeoutIsDown`.
- [ ] **T03**: The roll-up counts missing intervals as `no-check` downtime and closes outages (REQ-OAR-002). Verification: `AvailabilityRollupTest::testMissingHourIsTwelveDownIntervals`.
- [ ] **T04**: Deletion after thirteen months (REQ-OAR-003). Verification: `AvailabilityProbeJobTest::testRecordsOlderThanThirteenMonthsAreDeleted`.

## Reporting

- [ ] **T05**: `GET /api/availability/{portal}?months=12`, admin-only, monthly percentages and outages, CSV variant (REQ-OAR-004). Verification: `AvailabilityControllerTest::testNonAdminIsRefused`, `::testMonthlyPercentage`.
- [ ] **T06**: The "Availability" card in the `Reports` hub and the `AvailabilityReport` custom page (REQ-OAR-004). Verification: `tests/e2e/operate-availability-report.spec.ts` with seeded daily records.

## Docs, strings and validation

- [ ] **T07**: English and Dutch strings ("Availability", "How this is measured", "Download as CSV", the cause labels); a docs page for administrators and hosting parties on what the figure covers. Verification: `npm run lint`, `test:l10n`.
- [ ] **T08**: `openspec validate operate-availability-report --strict`.
