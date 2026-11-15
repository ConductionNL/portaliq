# Spec: portal-traffic-reporting

## ADDED Requirements

### Requirement: A segment MUST be a saved filter over sessions

A portal names segments in `traffic.segments[]`, each `{ id, name,
conditions: [{ dimension, operator, value }] }`. A dimension is one of
`channel`, `deviceType`, `browser`, `os`, `language`, `region`, `campaign`,
`source`, `medium`, `referrerHost`, `pageReferrer`, `visitorType`,
`userRef-present` or `goal:<id>`; an operator is one of `is`, `isNot`,
`contains`, `startsWith`; all conditions of a segment must hold. A segment
with a condition this app cannot evaluate is refused at configuration
time and absent from the resolved block. The aggregation computes each
portal-day once for all sessions (`segment: ''`) and once per segment
(`segment: <id>`), each its own `portalTrafficDaily` record; the record of
a segment that was removed is deleted on the next aggregation. The Traffic
page offers a segment selector and every widget reads the selected
segment's records.

#### Scenario: A segment produces its own daily record and the page switches to it

- **WHEN** a portal declares a segment `deviceType is desktop` and desktop and mobile visits were posted
- **THEN** after aggregation the day has a record with `segment: desktop` counting the desktop visits only, beside the record for all visits
- **AND** choosing the segment on the Traffic page shows the segment's figures

#### Scenario: Each operator and the AND across conditions

- **WHEN** a segment's conditions are evaluated against a session
- **THEN** `is` and `isNot` compare the whole value, `contains` a part and `startsWith` the start, ignoring case, and every condition must hold

@e2e exclude covered by TrafficSegmentsTest, one case per operator and one for the AND

#### Scenario: An unknown dimension is refused at configuration time

- **WHEN** a segment carries a condition on a dimension this app does not know
- **THEN** the segment is left out of the resolved configuration and no record is written for it

@e2e exclude covered by TrafficSegmentsTest

### Requirement: A roll-up portal MUST sum its members and never count its own

A portal with `traffic.rollupOf[]` naming other portals' slugs is a roll-up:
its daily record is the sum of its members' "all sessions" records for the
day (page views, sessions, visitors, events, pages merged by path,
referrers, campaigns, devices and the rest merged by key, rates re-derived
from the summed counts), computed after the ordinary portals, with
`rollupOf` and `members` (how many members had a record) on the record. A
member without a record for the day contributes nothing. The collector
refuses a roll-up portal's own events as `rollup-portal`. The Traffic page
says "Roll-up of N portals".

#### Scenario: A roll-up portal shows the summed page views

- **WHEN** a roll-up portal names two portals of which one has records for the day
- **THEN** its record for the day carries the sum of its members' page views and `members` says how many had data
- **AND** the Traffic page shows the roll-up note and the summed figures

#### Scenario: Nothing is counted twice and a quiet member is absent

- **WHEN** the members' records are summed
- **THEN** each member's numbers appear once, a shared page path is one row with both counts, and a member with no record is not an error

@e2e exclude covered by TrafficRollupSumTest and TrafficAggregationServiceTest

### Requirement: A scheduled report MUST be sent once per period

A portal names reports in `traffic.reports[]`, each `{ id, name, cadence,
recipients[], sections[] }` with `cadence` one of `daily`, `weekly`,
`monthly`, recipients being Nextcloud user ids or e-mail addresses, and
sections from `overview`, `pages`, `sources`, `visitors`, `goals`,
`funnels`, `forms`. An hourly job sends each report once per period (the
previous day, the ISO week that ended on Sunday, the previous month), by
mail (HTML and plain text) with the period's figures against the period
before and a link to the Traffic page, and as an in-app notification to a
user recipient. The period key is recorded before delivery, so a report is
never sent twice for one period.

#### Scenario: A due daily report is sent once and appears as a notification

- **WHEN** a portal declares a daily report to a Nextcloud user and the job runs twice
- **THEN** the user has exactly one notification for that report and period

#### Scenario: The mail carries the period's numbers against the period before

- **WHEN** a report is rendered for a period
- **THEN** each headline number is stated with its percent change and the previous figure, and the sections follow the report's list

@e2e exclude covered by TrafficReportMailTest and TrafficReportDeliveryTest; the throwaway instance has no mail transport

### Requirement: An alert MUST fire once per period

A portal names alerts in `traffic.alerts[]`, each `{ id, name, metric,
comparison, threshold, period, recipients[] }` with `metric` one of
`pageViews`, `sessions`, `visitors`, `notFound`, `goal:<id>`, `comparison`
one of `above`, `below`, `changeAbove` (percent against the previous
period) and `period` one of `day`, `week`. The report job evaluates each
alert after aggregation: `above` on the current period, `below` and
`changeAbove` on the last complete one. An alert fires the first run its
condition holds in a period and not again in that period; it reaches its
recipients by mail and, for a user, as a notification rendered by this
app's Notifier.

#### Scenario: An alert crosses its threshold and appears as a notification once

- **WHEN** an `above` alert on page views is declared with a threshold the day has passed and the job runs twice
- **THEN** the user has exactly one notification for that alert and period

#### Scenario: Below and change judge the last complete period

- **WHEN** a `below` or `changeAbove` alert is evaluated
- **THEN** it reads the last complete day or week, and `changeAbove` stays quiet when the period before had nothing to compare with

@e2e exclude covered by TrafficReportServiceTest

### Requirement: The daily records MUST be exportable

The daily records are the read API: `portalTrafficDaily` objects queried
through the object API by `portal`, `date` and `segment`. In addition
`GET /api/traffic/export?portal=&from=&to=&segment=&format=csv|json`,
admin only, downloads them: CSV with one row per portal-day-segment and
the scalar metrics (`portal, date, segment, pageViews, sessions, visitors,
newVisitors, returningVisitors, accounts, engagedSessions,
avgEngagementSeconds, bounceRate, conversionRate`), or JSON with the
records whole. A bad portal, range, segment or format is a 400 with a
reason. The Traffic page's Export button downloads what the page shows.

#### Scenario: The export returns CSV with the header and one row per day

- **WHEN** an admin requests the CSV export for a portal and a range
- **THEN** the response is `text/csv` with the column header and one row per day that has a record in the range
- **AND** an anonymous request is refused

### Requirement: A server-side caller MUST hold the portal's token

`occ portaliq:traffic:token <portal>` mints a token, prints it once and
stores its sha256 under `portal.traffic.serverToken`; the resolved traffic
block never carries it. `POST /api/traffic/server` accepts the collector's
envelope plus `remoteAddress` and `userAgent` on the batch or on each
event, names the portal in the body, and requires `Authorization: Bearer
<token>` matching that portal's hash; a wrong or missing token is a 401
with nothing stored. The batch goes through the same validator and the
same refusals as the collector, with the reported address and agent as
the request context, read and dropped the same way.

#### Scenario: The server API accepts a valid token and refuses a wrong one

- **WHEN** a batch is posted with the portal's token
- **THEN** it is stored under the reported visitor
- **AND** the same batch with a wrong token answers 401 and stores nothing

### Requirement: An access log MUST import as page views without assets or bots

`occ portaliq:traffic:import-log <portal> <file> --format=combined|json
--host=<origin>` parses Apache or Nginx lines into `page_view` events
(path, referrer, user agent, address for the hash and the region only,
timestamp), skips other methods, non-2xx answers, asset paths and bots,
batches them per visitor through the ingest service with old timestamps
allowed, and drops a line repeated within the import. The next
aggregation recomputes the days the views fall on.

#### Scenario: A sample log imports and the rollup counts the views

- **WHEN** a file with two page lines, an asset line and a bot line is imported
- **THEN** the command reports two views and the day's rollup lists the path with two views

#### Scenario: Sample lines in both formats

- **WHEN** a combined line and a JSON line are parsed
- **THEN** each yields the address, agent, UTC timestamp, path without query and referrer, and every reason to skip is honoured

@e2e exclude covered by TrafficLogParserTest

### Requirement: Script errors MUST be reported without the stack or the query string

When the portal enabled `js_error`, the client reports a script error on
`window`'s `error` event with the message, the source file's host and
path without its query string, the line, the column and a short hash of
the stack. Never the stack, never a query string. The rollup carries
`errors: [{ message, source, hits, pages }]` and the Script errors widget
lists them; a portal that did not enable the event reads "not measured".

#### Scenario: A script error lands and the page lists it

- **WHEN** a script on the built-in site throws while the portal enabled `js_error`
- **THEN** a `js_error` beacon with the message and no stack is posted and stored
- **AND** after aggregation the rollup lists the error and the Traffic page shows it
