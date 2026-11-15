# Spec: portal-traffic-analytics

## ADDED Requirements

### Requirement: A portal MUST decide what is measured, and the collector MUST enforce it

A portal record carries the enabled event names and dimensions. The collector
rejects any event name, or any dimension key, the serving portal has not
enabled.

#### Scenario: An unlisted event is refused, not silently dropped

- **WHEN** a client posts an event whose name the portal has not enabled
- **THEN** the collector rejects the batch entry with a reason
- **AND** the rejection is counted in a metric, so a misconfigured client is visible rather than merely ineffective

#### Scenario: An unlisted dimension is stripped

- **WHEN** an enabled event carries a dimension the portal has not enabled
- **THEN** the dimension is removed and the event is still stored

A collector that accepts whatever arrives does not have a configuration; it has
a default. The refusal is what makes the per-portal decision real, and it must
be observable: a silently dropped event and a correctly rejected one look
identical from the client.

### Requirement: A page view MUST be reported by the client, never inferred from a server-side read

Traffic events originate from the rendering client. The system MUST NOT derive
page views from OpenRegister reads, HTTP access logs, or CMS API calls.

#### Scenario: One page view causes several object reads

- **WHEN** rendering one page reads a portal, a menu and a page object
- **THEN** exactly one `page_view` is recorded

#### Scenario: A cached or statically built page is still counted

- **WHEN** a page is served from cache, or from a Docusaurus build hosted elsewhere
- **THEN** the visitor's client still reports `page_view` to the collector
- **AND** the count does not depend on portaliq being in the request path

Server-side reads describe what the CMS was asked for, not what a person saw.
Measured before writing this: `portalSession` carries no page-level data and
does not exist for anonymous visitors; no portaliq schema enables `logReads`,
so zero reads are recorded today; and a Docusaurus-rendered portal produces no
server-side signal at all, because the plugin fetches content at build time.

### Requirement: A session MUST be reconstructable into an ordered journey

Events carry a monotonically increasing sequence number within the session,
so the path from page to page can be reconstructed without relying on arrival
order or server clocks. A session id is carried when the client keeps one;
otherwise the aggregation step derives the session from the visitor hash and
the portal's inactivity window.

#### Scenario: Two pages viewed in order

- **WHEN** a visitor views `/` and then `/begrippen` in one session
- **THEN** the two events share a session (explicit id, or the same visitor hash within the window) and carry increasing sequence numbers
- **AND** the entrance, the exit and the transition between them are derivable

#### Scenario: Events arrive out of order

- **WHEN** a `page_view` beacon is delayed and arrives after the next one
- **THEN** the reconstructed order follows the sequence number, not the receipt time

A journey rebuilt from receipt timestamps is wrong exactly when it matters,
on slow connections, which are the ones worth knowing about.

### Requirement: A session MUST end by inactivity, and the timeout MUST be configured

A session ends after a configured period of inactivity (default 30 minutes, the
GA4 convention) and a new one begins on the next event.

#### Scenario: A visitor returns after the timeout

- **WHEN** a visitor's next event arrives after the inactivity window
- **THEN** it starts a new session with a new session id
- **AND** the previous session is closed at its last event, not at the new one

### Requirement: Measurement MUST be cookieless unless the portal persists a client id

By default the client writes NOTHING to the visitor's browser: no cookie, no
local storage, no session storage. The server identifies a visitor for one day
only, by a hash of a daily salt, the portal, the address and the user agent.
The salt rotates every UTC day and is not kept beyond it, so the hash cannot
be reversed and does not survive the day. A client id and a session id in the
envelope are OPTIONAL.

A portal MAY switch on `sensitive.persistClientId`. Only then does the client
generate and store a first-party id scoped to the portal, and only after the
visitor's consent when the portal requires consent. The id MUST NOT be derived
from an IP address, a Nextcloud session, or any identifier that exists outside
the portal. The collector drops a posted client id for a portal that did not
switch persistence on.

#### Scenario: The default mode stores nothing in the browser

- **WHEN** a portal measures traffic with the default configuration
- **THEN** the served client sends page views and stores no cookie, no local storage key and no session storage key
- **AND** the stored event carries a visitor hash and no client id

#### Scenario: A stale client sends an id the portal does not persist

- **WHEN** an event carries a `clientId` for a portal whose `persistClientId` is off
- **THEN** the event is stored without it

#### Scenario: The same browser visits two portals

- **WHEN** one browser visits two portals on different domains, both persisting ids
- **THEN** it carries two unrelated client ids
- **AND** neither portal can tell the visits belong to one browser

### Requirement: Sensitive measurement MUST be off by default and warned in the admin UI

Four switches change what a portal knows about a person rather than how much
traffic it counts: `persistClientId`, `accountLinking`, `heatmaps` and
`sessionRecording`. Each is off until an operator sets it to literally `true`,
and each shows its warning where it is switched.

#### Scenario: A switch is off unless literally true

- **WHEN** a portal record carries `sensitive.persistClientId: "true"` or `1`
- **THEN** the resolved configuration treats it as off

#### Scenario: A switched dimension follows its switch

- **WHEN** a portal lists `userRef` among its dimensions without `accountLinking`, or `region` with `regionGranularity: none`
- **THEN** the dimension is not stored

#### Scenario: The warning is shown where the switch is

- **WHEN** an editor opens a portal's traffic settings
- **THEN** each sensitive switch shows the consequence of turning it on
- **AND** the Traffic page shows a warning for a portal that has any of them on

### Requirement: An external site MUST be a portal of kind external

A site portaliq does not serve (a Docusaurus build, a municipal website) that
reports its traffic here is a `portal` record with `kind: external`: it has
domains and a traffic block, and no content. Everything else in the contract
applies to it unchanged.

#### Scenario: An external portal collects

- **WHEN** a portal of kind external enables measurement
- **THEN** the collector accepts events for it exactly as for a served site
- **AND** the Traffic page lists it like any other portal

### Requirement: Mail events MUST only be written server-side

`email_open` and `email_click` describe a message another app in this
instance sent. They are accepted only through the PHP ingest entry point and
refused from HTTP, whatever the portal enabled.

#### Scenario: A browser claims a mail event

- **WHEN** a client posts an `email_open` event over HTTP
- **THEN** the event is refused with the reason `event-server-side-only`
- **AND** the same event from a server-side caller is stored

### Requirement: The client timestamp MUST be clamped to the server clock

The client's timestamp is kept when it is in the past and within seven days;
a timestamp slightly ahead of the server is clamped to now; one more than five
minutes ahead, or older than seven days, is refused with a reason. A missing or
unparseable timestamp becomes the time of receipt.

#### Scenario: A skewed clock

- **WHEN** an event is dated two minutes ahead of the server
- **THEN** it is stored as occurring now

#### Scenario: A replayed queue

- **WHEN** an event is dated more than seven days ago
- **THEN** it is refused with the reason `timestamp-out-of-range`

### Requirement: Derived dimensions MUST be computed on the server and never accepted from the client

Device type, browser, operating system, language, region, referrer host,
channel and the campaign fields are derived from the request and the page
location. The address and the user agent are discarded in the same request.
A client that posts one of these fields directly has it ignored.

#### Scenario: A client posts a region

- **WHEN** an event carries `region` from the client
- **THEN** the stored record's region is the one derived on the server, or empty

### Requirement: An IP address MUST NOT be stored

The request IP MAY be used within the request to derive a coarse region and to
rate-limit. It MUST NOT be written to any event record, log line or aggregate.

#### Scenario: An event is stored

- **WHEN** an event is accepted
- **THEN** the stored record contains no IP address and no raw user agent in any field
- **AND** the derived region is no finer than the level the portal configured

#### Scenario: A bot is not a visitor

- **WHEN** the user agent identifies a crawler
- **THEN** the event is refused with the reason `bot`, and counted

### Requirement: Measurement MUST honour the portal's consent posture

A portal declares whether measurement runs before consent, and which events are
permitted without it.

#### Scenario: Consent has not been given

- **WHEN** a portal requires consent and the visitor has not given it
- **THEN** only events on the pre-consent list are accepted
- **AND** no client id is persisted in the visitor's browser

#### Scenario: Consent is withdrawn

- **WHEN** a visitor withdraws consent
- **THEN** the client id is discarded and a new session is not started

### Requirement: Raw events MUST be retained for a finite, configured period

Raw events are aggregated and then deleted on a schedule the portal configures.

#### Scenario: Retention elapses

- **WHEN** raw events pass the retention window
- **THEN** they are deleted
- **AND** the aggregates computed from them remain

#### Scenario: Retention is not configured

- **WHEN** a portal enables measurement without stating a retention period
- **THEN** the shipped default applies and is visible in the admin UI

An unbounded traffic log is a personal-data liability nobody chose. The default
must be stated rather than "keep everything until someone notices".

### Requirement: The collector MUST survive being a public endpoint

The collector is anonymous, unauthenticated and writable by anyone who can
reach the portal, the same posture as the portal itself.

#### Scenario: A flood of events from one source

- **WHEN** events arrive faster than the configured rate
- **THEN** the excess is refused with a rate-limit response
- **AND** the refusal is counted, so a flood is distinguishable from quiet

#### Scenario: An oversized or malformed batch

- **WHEN** a batch exceeds the size limit or fails validation
- **THEN** it is refused whole, with a reason, and nothing partial is stored

#### Scenario: An accepted batch answers without a body or a cookie

- **WHEN** a valid batch is posted anonymously
- **THEN** the response is 204 with no body and no Set-Cookie header
- **AND** a refused event inside it is counted under its reason on `/api/metrics`

#### Scenario: A pixel counts one event

- **WHEN** a page that cannot run scripts requests `/api/traffic/pixel.gif` with a portal, an event name and a location
- **THEN** one event goes through the same validation and the same rate limit as a posted batch
- **AND** the answer is a 1x1 GIF that is never cached

### Requirement: The served client MUST be one file for every renderer

`GET /api/traffic-client.js` serves the client to any origin with no session.
The built-in site renderer loads the same file, so a statically built portal
and a served one run the same bytes.

#### Scenario: An anonymous request for the client

- **WHEN** a visitor's browser requests `/api/traffic-client.js` with no session
- **THEN** it receives the script as `application/javascript`, cacheable for an hour

#### Scenario: The served site loads the client

- **WHEN** a portal with measurement enabled is rendered by the built-in renderer
- **THEN** the page carries the traffic script tag after the site bundle
- **AND** a page view reaches the collector

### Requirement: Cross-origin posting MUST work without a preflight

The collector reflects the request origin on public responses under
`/api/content` and `/api/traffic`, without credentials, so a browser on
another origin can post a `text/plain` batch as a simple request.

#### Scenario: A static site on its own domain posts a batch

- **WHEN** a page on another origin posts to the collector
- **THEN** the response carries `Access-Control-Allow-Origin` for that origin and `Vary: Origin`
- **AND** never `Access-Control-Allow-Credentials`

### Requirement: The content contract MUST carry the resolved measurement configuration

`GET /api/content/site` includes the portal's resolved `traffic` block and
the absolute `collector` URL, so a client sends only what the portal asked for
and a static site knows where to post.

#### Scenario: A client reads its configuration

- **WHEN** a consumer requests the site record for a measuring portal
- **THEN** the response carries `traffic.enabled`, the event and dimension lists, the consent posture and the sensitive switches
- **AND** a `collector` URL

### Requirement: Daily rollups MUST be readable through the ordinary object API

The aggregation job writes one `portalTrafficDaily` object per portal per UTC
day and recomputes it idempotently from the raw events. Reports read those
objects through OpenRegister's object API rather than a bespoke endpoint.

#### Scenario: The job runs twice

- **WHEN** the aggregation job runs twice over the same raw events
- **THEN** the day's counts are the same after the second run as after the first

#### Scenario: A rollup exists after the job

- **WHEN** events were accepted for a portal and the job has run
- **THEN** a `portalTrafficDaily` object for that portal and day exists with a non-zero page view count

### Requirement: The Traffic page MUST show what was measured, and say when it was not

#### Scenario: Measurement is disabled for a portal

- **WHEN** a portal has no measurement enabled
- **THEN** the Traffic page says so for that portal
- **AND** it does NOT render an empty chart

#### Scenario: A measured portal without figures is not the same as an unmeasured one

- **WHEN** a portal measures traffic but the aggregation job has written no daily record for the range
- **THEN** the Traffic page says no traffic was recorded yet, in different words and a different element than "not measured"
- **AND** neither state renders a chart

#### Scenario: The page shows the numbers for a measured portal

- **WHEN** a portal measures traffic and a daily record exists for the range
- **THEN** the Traffic page shows page views, sessions, visitors and engaged sessions for the last 30 days, a chart per day, the top pages with entrances and exits, the top transitions, and the sources by channel
- **AND** switching the portal selector to another portal switches every widget on the page

A zero and an unmeasured are different facts, and a chart showing zero for a
portal that was never instrumented is the more convincing of the two lies.
