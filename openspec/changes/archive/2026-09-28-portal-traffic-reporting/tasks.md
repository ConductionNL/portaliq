# Tasks: portal-traffic-reporting

## 1. Segments and roll-ups

- [x] 1.1 `TrafficSegments`: definitions (unknown dimension or operator refused at configuration time), per-session matching (`is`, `isNot`, `contains`, `startsWith`, AND-combined; `visitorType`, `userRef-present`, `goal:<id>` derived), filter; unit tests per operator.
- [x] 1.2 Aggregation: one record per portal-day for all visits plus one per segment (`segment` set), stale segment records deleted; `TrafficEventStore::findDailyRows`, `dailyBetween`, `deleteDaily`.
- [x] 1.3 `TrafficRollupSum`: a roll-up portal's day as the sum of its members' "all visits" records, merged by key, rates re-derived; computed after the ordinary portals; the ingest refuses a roll-up portal's events (`rollup-portal`); unit tests for the sums, no double count, a member without data.
- [x] 1.4 Store and page: segment selector on the overview, every widget reads the selected segment's rows; "Roll-up of N portals" note.

## 2. Reports and alerts

- [x] 2.1 `TrafficReportDefinitions` (reports, alerts, roll-up members), `TrafficReportPeriods` (yesterday, the ISO week that ended, the previous month; the current day or week for an `above` alert), `TrafficReportNumbers` (fold, metric, percent change).
- [x] 2.2 `TrafficReportService`: due once per period key, recorded in app config before delivery; alerts fire once per period; `TrafficReportJob` hourly.
- [x] 2.3 `TrafficReportMail` (subject, sections with each number against the period before, plain text) and `TrafficReportDelivery` (IMailer HTML template + plain, IUserManager address lookup, INotificationManager for user recipients); `Notification\Notifier` registered in `Application`.
- [x] 2.4 Unit tests: due-ness, the mail's numbers, an alert that fires once per period and again the next, the delivery per recipient kind.

## 3. Export, server API, log import, errors

- [x] 3.1 `TrafficExport` (CSV one row per portal-day-segment with the scalar metrics, JSON whole) and `TrafficReportController::export` (admin, `NoCSRFRequired` for the navigated download); the Export button on the overview.
- [x] 3.2 `TrafficServerToken` (mint with `ISecureRandom`, sha256 stored on the portal, `hash_equals` verify), `occ portaliq:traffic:token <portal>` shown once, `TrafficController::server` (401 on a wrong or missing token, per-event `remoteAddress` and `userAgent`).
- [x] 3.3 `TrafficLogParser` (combined and JSON, assets and bots skipped, UTC timestamps), `TrafficLogImporter` (per-visitor batches, duplicates within the import dropped, `allowOld` so the validator keeps old timestamps), `occ portaliq:traffic:import-log <portal> <file> --format --host`.
- [x] 3.4 Client: `js_error` on `window` `error` with message, source without query, line, column, stack hash; `TrafficErrorStats` and `errors[]` on the rollup; `TrafficErrors` widget.
- [x] 3.5 Schemas: `portal.traffic.segments`, `rollupOf`, `reports`, `alerts`, `serverToken`; `js_error` in the event enums; `portalTrafficDaily.segment`, `rollupOf`, `members`, `errors`; versions bumped (register 0.20.0, portal 0.5.0, portalTrafficDaily 0.4.0, portalTrafficEvent 0.3.0); every new string catalogued.

## 4. Documentation and proof

- [x] 4.1 `docs/operations/traffic-analytics.md`: segments, roll-ups, reports, alerts, the read API and the export, the server API and the token, the log import, script errors.
- [x] 4.2 Seed: a third portal `rollup-tilburg-venray` with `rollupOf: [open-tilburg, open-venray]`.
- [x] 4.3 E2E `tests/e2e/traffic-reporting.spec.ts` on the throwaway instance, one test per scenario.
