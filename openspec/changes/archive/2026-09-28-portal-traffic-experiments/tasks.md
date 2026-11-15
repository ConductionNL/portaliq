# Tasks: portal-traffic-experiments

## 1. Page experiments

- [x] 1.1 `TrafficExperimentDefinitions`: `traffic.experiments[]` normalised (id, page, two or more variants each with a page route or text changes, goal, status, started and stopped moments); drafts dropped from the resolved block; unit tests.
- [x] 1.2 `TrafficExperiments`: sessions and conversions per variant from the first tagged event, a stopped experiment counting nothing after its stop, the two-proportion z-test, a winner only with thirty sessions per variant and 95 percent; unit tests on a known table.
- [x] 1.3 Validator: `experiment` and `variant` params survive only when they name a running experiment and one of its variants.
- [x] 1.4 Client: the running experiment on the current route, a weighted sticky pick (client id or per-load seed), a soft redirect to a variant page through `replaceState` and `popstate`, text changes applied and re-applied on render, the tag on every event; node tests for the split and the stickiness.
- [x] 1.5 Rollup `experiments[]`, roll-up sum merging by id with the verdict re-derived; the summary folds the range and re-derives the verdict in the browser; widget `TrafficExperiments` with "Not enough data".

## 2. Heatmaps

- [x] 2.1 Resolver: `heat_click` and `heat_scroll` exist only under `sensitive.heatmaps`; validator refuses them as `sensitive-off` otherwise and keeps positions only (`x`, `y`, `vw`, `tag`, `selector`, `depth`), the selector stripped of ids and attributes.
- [x] 2.2 Client: a click as fractions of the document with a viewport bucket, a tag and a safe selector; the deepest scroll per page view, sent when the page view ends.
- [x] 2.3 `TrafficHeatmapStats`: the fifty by fifty click grid and the scroll deciles per page; rollup `heatmaps[]` only while the switch is on; unit tests.
- [x] 2.4 Widget `TrafficHeatmap`: a page picker, the grid on a canvas over a plain rectangle, the deciles as bars, "off for this portal".

## 3. Session recording

- [x] 3.1 `src/traffic/recorder.js` as a second webpack entry served from `/api/traffic-recorder.js`, loaded by the client only when `mayRecord` (switch on, not an external portal, consent where required): masked snapshots, pointer, clicks, scrolls, viewport, navigations, posted in chunks, stopping at 2 MB.
- [x] 3.2 `TrafficRecordingMask`: every chunk reduced on arrival to lengths, allowed attributes and numbers; `TrafficRecordingService` with the four gates and the two budgets; `TrafficRecordingStore`; `POST /api/traffic/recording`; unit tests.
- [x] 3.3 Schema `portalTrafficRecording` (admin-readable, `expires`), purged by the aggregation run.
- [x] 3.4 Widget `TrafficRecordings` and the player modal `TrafficRecordingPlayer` (srcdoc, sandbox without scripts); the overview warning says how many recordings exist and how long they are kept.

## 4. Settings, schemas, documentation and proof

- [x] 4.1 Schemas: `portal.traffic.experiments`, the sensitive descriptions rewritten now the switches do something, `portalTrafficEvent` enum, `portalTrafficDaily.experiments` and `heatmaps`, `portalTrafficRecording`; versions bumped (register 0.21.0, portal 0.6.0, daily 0.5.0, event 0.4.0); every new string catalogued.
- [x] 4.2 A custom form field for the `sensitive` block checked and found not feasible on nc-vue 2.37 (no consumer of `appliesTo`); recorded in the proposal and the docs.
- [x] 4.3 `docs/operations/traffic-analytics.md`: experiments, heatmaps, session recording (what is masked, why an external portal never records, the budgets, the retention).
- [x] 4.4 Seed: an external portal `open-extern` with recording on, so "never on an external portal" is proven against a real record; `traffic-outcomes.spec.ts` no longer asserts a missing path inside a top-ten list.
- [x] 4.5 E2E `tests/e2e/traffic-experiments.spec.ts` on the throwaway instance, one test per scenario.
