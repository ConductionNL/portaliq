# Tasks: operate-availability-report

## Measuring

- [x] **T01**: `portalAvailabilityDaily` and `portalAvailabilityOutage` schemas with `portal`, register version bump (REQ-OAR-001). Verification: register import test.
- [x] **T02**: `lib/BackgroundJob/AvailabilityProbeJob.php` registered in `appinfo/info.xml`: check, classify, update the daily record and the open outage (REQ-OAR-001). Verification: `AvailabilityProbeJobTest::testSiteErrorIsDown`, `::testDegradedHealthIsDegraded`, `::testTimeoutIsDown`.
- [x] **T03**: The roll-up counts missing intervals as `no-check` downtime and closes outages (REQ-OAR-002). Verification: `AvailabilityRollupTest::testMissingHourIsTwelveDownIntervals`.
- [x] **T04**: Deletion after thirteen months (REQ-OAR-003). Verification: `AvailabilityProbeJobTest::testRecordsOlderThanThirteenMonthsAreDeleted`.

## Reporting

- [x] **T05**: `GET /api/availability/{portal}?months=12`, admin-only, monthly percentages and outages, CSV variant (REQ-OAR-004). Verification: `AvailabilityControllerTest::testNonAdminIsRefused`, `::testMonthlyPercentage`.
- [x] **T06**: The "Availability" card in the `Reports` hub and the `AvailabilityReport` custom page (REQ-OAR-004). Verification: `tests/e2e/operate-availability-report.spec.ts` with seeded daily records.

## Docs, strings and validation

- [x] **T07**: English and Dutch strings ("Availability", "How this is measured", "Download as CSV", the cause labels); a docs page for administrators and hosting parties on what the figure covers. Verification: `npm run lint`, `test:l10n`.
- [x] **T08**: `openspec validate operate-availability-report --strict`.
