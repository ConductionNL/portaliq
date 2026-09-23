# Tasks: portal-page-traffic

## 1. Data

- [x] 1.1 `TrafficPagePath`: the in-site route of an event, and of a page's `route`, by the rule the traffic client's `siteRoute` uses.
- [x] 1.2 `TrafficJourneyStats`: page rows and transitions keyed by that route; each row gains `sessions`, `visitors`, `engagedSessions`, `referrers` and `outbound`.
- [x] 1.3 `TrafficRollup` passes each session's engagement to the page rows.
- [x] 1.4 `TrafficRollupSum` sums the new fields by page and leaves out a figure a member row lacked.
- [x] 1.5 Register: `portalTrafficDaily` 0.6.0 describes the new page fields; register 0.26.0 so an upgrade re-imports; the mock register's page rows carry them.

## 2. Back-fill

- [x] 2.1 `TrafficBackfillService::backfill()`: every retained day of every ordinary portal, then the roll-ups, never over a more complete stored day (`TrafficDayGuard`). Built from the job's own day rebuild, `TrafficAggregationService::dayRecords()` and `writeDay()`.
- [x] 2.2 `TrafficAggregationJob` runs `TrafficBackfillService::runOnce()` after the aggregation, keyed on an app config marker, in its own try.
- [x] 2.3 `occ portaliq:traffic:reaggregate` runs the back-fill on demand.

## 3. Endpoint

- [x] 3.1 `TrafficPageReport`: folds one route's rows over a period.
- [x] 3.2 `TrafficPageController::page` and the `trafficPage#page` route, admin only; unit tests for the fold, the default period, `measured`, the refusals and the posture.

## 4. Frontend

- [x] 4.1 `PortalTrafficKpi` gains a page scope: page endpoint, "Not measured" and "Not available for this period" from the answer, days-covered caption.
- [x] 4.2 `PageTrafficFlow` widget, `direction: incoming | outgoing`.
- [x] 4.3 Manifest: four cards, the page details and the two flow widgets on `PageDetail`.
- [x] 4.4 `en` and `nl` strings.
- [x] 4.5 e2e: `tests/e2e/page-traffic.spec.ts` references every scenario (written, not yet run against an instance).
