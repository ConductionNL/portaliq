# Tasks: portal-traffic-path-explorer

## 1. Backend

- [x] 1.1 `TrafficPaths`: a visit's path (page views only, reloads collapsed), the fold of visits into weighted paths, and the explorer's steps, "+N more" nodes, drop-offs, links and trail; PHPUnit with hand-built fixtures.
- [x] 1.2 `TrafficEventStore::eventsForPaths`: a bounded read that says when it stopped at its limit and drops what the paths never read.
- [x] 1.3 `TrafficPathService`: the portal, its retention and segment, days read newest first under the event cap, the five-minute cache; PHPUnit.
- [x] 1.4 `TrafficPathController::paths` and the `trafficPath#paths` route: admin only, 400 with a reason, `Cache-Control: private, no-store`; PHPUnit.

## 2. Frontend

- [x] 2.1 `src/lib/trafficPaths.js`: the SVG layout as a pure function; `tests/traffic-paths.spec.mjs` asserts its geometry.
- [x] 2.2 `TrafficPathExplorer` widget: start or end point, steps, keyboard-operable nodes, the table alternative, the retention and cap notices.
- [x] 2.3 Manifest: the explorer replaces Journeys on the Traffic page, full width; `TrafficJourneys` leaves the registry.

## 3. Tests and docs

- [x] 3.1 e2e: `tests/e2e/traffic-path-explorer.spec.ts` covers every scenario (not run in this change: no instance mounts the branch).
- [x] 3.2 The Traffic page scenario of portal-traffic-analytics names the explorer, not the top transitions.
