# Tasks: portal-traffic-kpi-cards

## 1. Backend

- [x] 1.1 `TrafficReportController::summary` and the `trafficReport#summary` route; unit tests for the fold, the default period, the refusals and the admin-only posture.

## 2. Frontend

- [x] 2.1 `PortalTrafficKpi` widget: wraps nc-vue's `CnStatWidget`, reads the portal from the detail page, says "Not measured" for an unmeasured portal, links to the Traffic page with the portal selected.
- [x] 2.2 `TrafficKpi` widget: one headline number of the Traffic page, read from the report store.
- [x] 2.3 `TrafficOverview` drops its four tiles and selects the portal named in `?portal=`.
- [x] 2.4 Manifest: four cards on `PortalDetail` above the details; four cards on `Traffic` below the overview.
- [x] 2.5 e2e: tile test ids kept on the new cards.
