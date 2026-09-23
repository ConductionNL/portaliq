# Tasks: portal-page-traffic

## 1. Data

- [ ] 1.1 `TrafficPagePath`: the in-site route of an event, and of a page's `route`, by the rule the traffic client's `siteRoute` uses.
- [ ] 1.2 `TrafficJourneyStats`: page rows and transitions keyed by that route; each row gains `sessions`, `visitors`, `engagedSessions`, `referrers` and `outbound`.
- [ ] 1.3 `TrafficRollup` passes each session's engagement to the page rows.
- [ ] 1.4 `TrafficRollupSum` sums the new fields by page and leaves out a figure a member row lacked.
- [ ] 1.5 Register: `portalTrafficDaily` 0.6.0 describes the new page fields; register 0.26.0 so an upgrade re-imports; the mock register's page rows carry them.

## 2. Back-fill

- [ ] 2.1 `TrafficAggregationService::backfill()`: every retained day of every ordinary portal, then the roll-ups, never over a more complete stored day.
- [ ] 2.2 `run()` back-fills once, keyed on an app config marker.
- [ ] 2.3 `occ portaliq:traffic:reaggregate` runs the back-fill on demand.

## 3. Endpoint

- [ ] 3.1 `TrafficPageReport`: folds one route's rows over a period.
- [ ] 3.2 `TrafficPageController::page` and the `trafficPage#page` route, admin only; unit tests for the fold, the default period, `measured`, the refusals and the posture.

## 4. Frontend

- [ ] 4.1 `PortalTrafficKpi` gains a page scope: page endpoint, "Not measured" and "Not available for this period" from the answer, days-covered caption.
- [ ] 4.2 `PageTrafficFlow` widget, `direction: incoming | outgoing`.
- [ ] 4.3 Manifest: four cards, the page details and the two flow widgets on `PageDetail`.
- [ ] 4.4 `en` and `nl` strings.
- [ ] 4.5 e2e: `tests/e2e/page-traffic.spec.ts` references every scenario.
