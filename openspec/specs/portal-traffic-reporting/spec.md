# portal-traffic-reporting Specification

## Purpose

Traffic figures are read, segmented, summed across portals, exported, mailed as scheduled reports and watched by alerts, and can be fed from a server, an access log or script errors. This spec fixes those reporting and import paths and what each may carry, and the public collector that feeds them: what it accepts, how it answers, and how long raw events are kept.

## Requirements

### Requirement: A segment MUST be a saved filter over sessions

A segment MUST be a saved filter over sessions.

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

A roll-up portal MUST sum its members and never count its own.

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

A scheduled report MUST be sent once per period.

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

An alert MUST fire once per period.

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

The daily records MUST be exportable.

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

A server-side caller MUST hold the portal's token.

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

An access log MUST import as page views without assets or bots.

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

Script errors MUST be reported without the stack or the query string.

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

### Requirement: An external site MUST be a portal of kind external

An external site MUST be a portal of kind external.

A site portaliq does not serve (a Docusaurus build, a municipal website) that reports its traffic here is a `portal` record with `kind: external`: it has domains and a traffic block, and no content. Everything else in the contract applies to it unchanged.

#### Scenario: An external portal collects and is listed
@e2e exclude Static: drawn on the boards PtPortals and PtVerkeer
- **WHEN** a portal of kind external enables measurement
- **THEN** the collector accepts events for it exactly as for a served site
- **AND** the portals list shows it with the kind "Extern", and the Traffic page offers it in the Portal choice like any other portal

### Requirement: A portal MUST decide what is measured, and the collector MUST enforce it

A portal MUST decide what is measured, and the collector MUST enforce it.

A portal record carries the enabled event names and dimensions. The collector
rejects any event name, or any dimension key, the serving portal has not
enabled.

#### Scenario: An unlisted event is refused, not silently dropped
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::an-unlisted-event-is-refused-not-silently-dropped`
- **WHEN** a client posts an event whose name the portal has not enabled
- **THEN** the collector rejects the batch entry with a reason
- **AND** the rejection is counted in a metric, so a misconfigured client is visible rather than merely ineffective

#### Scenario: An unlisted dimension is stripped
@e2e exclude covered by TrafficEventValidatorTest::testAnUnlistedDimensionIsStrippedNotFatal; a stripped key leaves nothing on a page to assert
- **WHEN** an enabled event carries a dimension the portal has not enabled
- **THEN** the dimension is removed and the event is still stored

A collector that accepts whatever arrives does not have a configuration; it has
a default. The refusal is what makes the per-portal decision real, and it must
be observable: a silently dropped event and a correctly rejected one look
identical from the client.

### Requirement: A page view MUST be reported by the client, never inferred from a server-side read

A page view MUST be reported by the client, never inferred from a server-side read.

Traffic events originate from the rendering client. The system MUST NOT derive
page views from OpenRegister reads, HTTP access logs, or CMS API calls.

#### Scenario: One page view causes several object reads
@e2e exclude no read path writes a page view; only the collector stores one, covered by TrafficIngestServiceTest
- **WHEN** rendering one page reads a portal, a menu and a page object
- **THEN** exactly one `page_view` is recorded

#### Scenario: A cached or statically built page is still counted
@e2e exclude a Docusaurus build lives outside this repo; the served client reporting a page view is covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::the-served-site-loads-the-client`
- **WHEN** a page is served from cache, or from a Docusaurus build hosted elsewhere
- **THEN** the visitor's client still reports `page_view` to the collector
- **AND** the count does not depend on portaliq being in the request path

Server-side reads describe what the CMS was asked for, not what a person saw.
Measured before writing this: `portalSession` carries no page-level data and
does not exist for anonymous visitors; no portaliq schema enables `logReads`,
so zero reads are recorded today; and a Docusaurus-rendered portal produces no
server-side signal at all, because the plugin fetches content at build time.

### Requirement: Mail events MUST only be written server-side

Mail events MUST only be written server-side.

`email_open` and `email_click` describe a message another app in this
instance sent. They are accepted only through the PHP ingest entry point and
refused from HTTP, whatever the portal enabled.

#### Scenario: A browser claims a mail event
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::a-browser-claims-a-mail-event`
- **WHEN** a client posts an `email_open` event over HTTP
- **THEN** the event is refused with the reason `event-server-side-only`
- **AND** the same event from a server-side caller is stored

### Requirement: The client timestamp MUST be clamped to the server clock

The client timestamp MUST be clamped to the server clock.

The client's timestamp is kept when it is in the past and within seven days;
a timestamp slightly ahead of the server is clamped to now; one more than five
minutes ahead, or older than seven days, is refused with a reason. A missing or
unparseable timestamp becomes the time of receipt.

#### Scenario: A skewed clock
@e2e exclude covered by TrafficEventValidatorTest::testTheTimestampIsClampedToTheServerClock
- **WHEN** an event is dated two minutes ahead of the server
- **THEN** it is stored as occurring now

#### Scenario: A replayed queue
@e2e exclude covered by TrafficEventValidatorTest::testTheTimestampIsClampedToTheServerClock
- **WHEN** an event is dated more than seven days ago
- **THEN** it is refused with the reason `timestamp-out-of-range`

### Requirement: The collector MUST survive being a public endpoint

The collector MUST survive being a public endpoint.

The collector is anonymous, unauthenticated and writable by anyone who can
reach the portal, the same posture as the portal itself.

#### Scenario: A flood of events from one source
@e2e exclude a flood would rate-limit the shared rig; the anonymous limit on collect and pixel is pinned by RateLimitAttributesTest, the count per reason by TrafficMetricsTest
- **WHEN** events arrive faster than the configured rate
- **THEN** the excess is refused with a rate-limit response
- **AND** the refusal is counted, so a flood is distinguishable from quiet

#### Scenario: An oversized or malformed batch
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::an-oversized-or-malformed-batch`
- **WHEN** a batch exceeds the size limit or fails validation
- **THEN** it is refused whole, with a reason, and nothing partial is stored

#### Scenario: An accepted batch answers without a body or a cookie
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::an-accepted-batch-answers-without-a-body-or-a-cookie`
- **WHEN** a valid batch is posted anonymously
- **THEN** the response is 204 with no body and no Set-Cookie header
- **AND** a refused event inside it is counted under its reason on `/api/metrics`

#### Scenario: A pixel counts one event
@e2e exclude covered by TrafficControllerTest::testThePixelIngestsOneEventAndAnswersAGif
- **WHEN** a page that cannot run scripts requests `/api/traffic/pixel.gif` with a portal, an event name and a location
- **THEN** one event goes through the same validation and the same rate limit as a posted batch
- **AND** the answer is a 1x1 GIF that is never cached

### Requirement: The served client MUST be one file for every renderer

The served client MUST be one file for every renderer.

`GET /api/traffic-client.js` serves the client to any origin with no session.
The built-in site renderer loads the same file, so a statically built portal
and a served one run the same bytes.

#### Scenario: An anonymous request for the client
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::an-anonymous-request-for-the-client`
- **WHEN** a visitor's browser requests `/api/traffic-client.js` with no session
- **THEN** it receives the script as `application/javascript`, cacheable for an hour

#### Scenario: The served site loads the client
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::the-served-site-loads-the-client`
- **WHEN** a portal with measurement enabled is rendered by the built-in renderer
- **THEN** the page carries the traffic script tag after the site bundle
- **AND** a page view reaches the collector

### Requirement: Cross-origin posting MUST work without a preflight

Cross-origin posting MUST work without a preflight.

The collector reflects the request origin on public responses under
`/api/content` and `/api/traffic`, without credentials, so a browser on
another origin can post a `text/plain` batch as a simple request.

#### Scenario: A static site on its own domain posts a batch
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::a-static-site-on-its-own-domain-posts-a-batch`
- **WHEN** a page on another origin posts to the collector
- **THEN** the response carries `Access-Control-Allow-Origin` for that origin and `Vary: Origin`
- **AND** never `Access-Control-Allow-Credentials`

### Requirement: The content contract MUST carry the resolved measurement configuration

The content contract MUST carry the resolved measurement configuration.

`GET /api/content/site` includes the portal's resolved `traffic` block and
the absolute `collector` URL, so a client sends only what the portal asked for
and a static site knows where to post.

#### Scenario: A client reads its configuration
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::a-client-reads-its-configuration`
- **WHEN** a consumer requests the site record for a measuring portal
- **THEN** the response carries `traffic.enabled`, the event and dimension lists, the consent posture and the sensitive switches
- **AND** a `collector` URL

### Requirement: Daily rollups MUST be readable through the ordinary object API

Daily rollups MUST be readable through the ordinary object API.

The aggregation job writes one `portalTrafficDaily` object per portal per UTC
day and recomputes it idempotently from the raw events. Reports read those
objects through OpenRegister's object API rather than a bespoke endpoint.

#### Scenario: The job runs twice
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::the-job-runs-twice`
- **WHEN** the aggregation job runs twice over the same raw events
- **THEN** the day's counts are the same after the second run as after the first

#### Scenario: A rollup exists after the job
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::a-rollup-exists-after-the-job`
- **WHEN** events were accepted for a portal and the job has run
- **THEN** a `portalTrafficDaily` object for that portal and day exists with a non-zero page view count

### Requirement: Raw events MUST be retained for a finite, configured period

Raw events MUST be retained for a finite, configured period.

Raw events are aggregated and then deleted on a schedule the portal configures.

#### Scenario: Retention elapses
@e2e exclude a retention window cannot elapse inside an e2e run; the purge is ExpiredRowPurge, run by the aggregation job
- **WHEN** raw events pass the retention window
- **THEN** they are deleted
- **AND** the aggregates computed from them remain

#### Scenario: Retention is not configured
@e2e exclude covered by TrafficConfigResolverTest::testRetentionAndSessionWindowAlwaysHaveAValue; drawn on the board PtVerkeer ("Paden zijn alleen voor de laatste 90 dagen bewaard")
- **WHEN** a portal enables measurement without stating a retention period
- **THEN** the shipped default applies and is visible in the admin UI

An unbounded traffic log is a personal-data liability nobody chose. The default
must be stated rather than "keep everything until someone notices".
