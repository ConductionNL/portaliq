# Spec: portal-traffic-experiments

## ADDED Requirements

### Requirement: A page experiment MUST be evaluated per session against its goal

A portal names experiments in `traffic.experiments[]`, each `{ id, name,
status, page, variants: [{ id, name, weight, pageRoute | changes: [{
selector, text }] }], goal, startedAt, stoppedAt }` with `status` one of
`draft`, `running`, `stopped`. Only a running or stopped experiment is in
the resolved block; a draft reaches nobody. The client puts a visitor of
the experiment's page on one variant, by weight, sticky for the visit
(derived from the client id when the portal persists one, from a per-load
seed otherwise, stored nowhere), applies it (a soft redirect to the
variant page without a reload, or the text changes through textContent),
and tags every event of that session with `experiment` and `variant`. The
collector keeps the tag only when it names a running experiment and one
of its variants. The aggregation counts a session for the variant of its
first tagged event, converts it when it meets the experiment's goal, and
counts nothing tagged after a stopped experiment's `stoppedAt`. The
rollup carries `experiments: [{ id, name, status, variants: [{ id, name,
sessions, conversions, rate }], winner, confidence }]`.

#### Scenario: Visitors are split over the variants and a session stays on its variant

- **WHEN** a running experiment with two text variants is on a page and several fresh visitors open it
- **THEN** both variant ids appear among the stored events, a visitor on the changed variant sees the changed text
- **AND** every event of one visit carries the same experiment and variant, page views included

#### Scenario: A variant page is shown in place of the experiment's page

- **WHEN** a variant names another page route and a visitor lands on it
- **THEN** the visitor sees the variant page without a reload and the page view carries the variant

#### Scenario: The rollup lists the experiment per variant and the widget says not enough data

- **WHEN** the day is aggregated after a handful of tagged visits
- **THEN** the rollup's experiment row carries sessions per variant
- **AND** the Experiments widget lists the variants and reads "Not enough data"

#### Scenario: A stopped experiment counts no further

- **WHEN** an experiment is stopped at a moment and a session is tagged after it
- **THEN** that session is not counted and the experiment's rows stay

@e2e exclude covered by TrafficExperimentsTest; the moment is set by the test, not reachable through a browser within a run

### Requirement: A winner MUST only be named with enough sessions and a significant difference

The two best variants are compared with a two-proportion z-test on the
pooled proportion. A winner is named only when every variant has at
least thirty sessions and the two-sided confidence is at least 0.95;
otherwise `winner` is empty and `confidence` still says how far apart the
two are. The Traffic page re-derives the verdict from the range's summed
counts, never averages the days, and shows "Not enough data" under thirty
sessions per variant.

#### Scenario: Thirty sessions per variant and a confident difference

- **WHEN** variants have 60 and 60 sessions with 2 and 12 conversions
- **THEN** the second is the winner at 0.99 confidence, and with 29 sessions on one variant no winner is named whatever the rates

@e2e exclude covered by TrafficExperimentsTest and traffic-summary.spec.mjs on the same tables

### Requirement: Heatmaps MUST be off by default and hold positions, never content

`heat_click` and `heat_scroll` exist only under `sensitive.heatmaps`: with
the switch off the collector refuses them as `sensitive-off` whatever the
events list says; with it on they are accepted without being listed. A
click carries `x` and `y` as fractions of the document, `vw` as a viewport
width bucket, `tag` and a `selector` of tags and classes with every id and
attribute stripped; a scroll carries `depth` as a fraction. The rollup
carries `heatmaps: [{ path, samples, clicks: [{ x, y, count }] on a fifty by
fifty grid, scroll: [ten deciles] }]` only while the switch is on. The
Heatmap widget draws the grid on a plain rectangle, says it is not a
screenshot, and reads "off for this portal" with the switch off.

#### Scenario: A heatmap event is refused while the switch is off

- **WHEN** a `heat_click` is posted to a portal with the switch off
- **THEN** it is refused and counted as `sensitive-off` and nothing is stored

#### Scenario: A click lands on the grid and the widget draws it

- **WHEN** the switch is on and a visitor clicks on the built-in site
- **THEN** a `heat_click` with fractions reaches the collector, the day's rollup has a grid cell for the page
- **AND** the Heatmap widget draws the page

### Requirement: Session recording MUST be off by default, consented and bounded

With `sensitive.sessionRecording` on, the client loads a separate recorder
script from `/api/traffic-recorder.js` and only then: never on a portal of
`kind: external` (this app does not serve that page, its document is not
ours to record), never before consent where the portal requires it. The
recorder posts chunks to `POST /api/traffic/recording`; the collector
refuses a chunk for a portal that does not measure, whose switch is off,
that is external, or before consent, refuses a chunk over 256 KB and a
visit over 2 MB, and stores the visit as one `portalTrafficRecording`
object that expires with the portal's raw events and is purged by the
aggregation. The Recordings widget lists the portal's visits with a
player, and the overview's warning says how many recordings exist and
how long they are kept.

#### Scenario: The recorder is served but never requested while the switch is off

- **WHEN** the switch is off and a visitor opens the built-in site
- **THEN** `GET /api/traffic-recorder.js` answers JavaScript to anyone, and the visit never requests it

#### Scenario: A consented visit produces a masked recording, listed with a player

- **WHEN** the switch is on and a visitor opens the built-in site
- **THEN** a `portalTrafficRecording` for the portal exists whose chunks do not contain the page's heading text
- **AND** the Recordings widget lists it and the player opens on it

#### Scenario: Recording waits for consent where consent is required

- **WHEN** the portal requires consent and a visitor has not given it
- **THEN** the recorder is not requested until `window.portaliqTraffic.consent(true)` is called

#### Scenario: An external portal never records

- **WHEN** an external portal has the switch on and the client runs for it
- **THEN** the recorder is never requested, and a chunk posted for an external portal is refused as `external-portal`

### Requirement: A session recording MUST never hold text or a typed value

The recorder writes a text node as its length and an input as the length
of its value, drops scripts, writes images and frames as boxes and keeps
attributes from a short layout list only. The collector walks every chunk
again and keeps nothing else: an unknown key, a text, a value, an `alt`,
a `data-*`, an `href` outside a stylesheet link, a `url(` in a style are
dropped before storage. The player rebuilds the document from the masked
tree into an iframe with `srcdoc` and `sandbox="allow-same-origin"` and
no `allow-scripts`.

#### Scenario: A leaked text or value never reaches the store

- **WHEN** a chunk arrives with a text node that carries its text, an input that carries its value and an image with its source
- **THEN** the stored chunk holds the text's length, the value's length and the image's box, and none of the three strings

@e2e exclude covered by TrafficRecordingMaskTest and TrafficRecordingServiceTest; the e2e scenario above asserts the absence of the page heading in a real recording
