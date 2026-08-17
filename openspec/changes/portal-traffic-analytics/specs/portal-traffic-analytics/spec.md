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
be observable — a silently dropped event and a correctly rejected one look
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

Events carry a client id, a session id and a monotonically increasing sequence
number within the session, so the path from page to page can be reconstructed
without relying on arrival order or server clocks.

#### Scenario: Two pages viewed in order

- **WHEN** a visitor views `/` and then `/begrippen` in one session
- **THEN** the two events share a session id and carry increasing sequence numbers
- **AND** the entrance, the exit and the transition between them are derivable

#### Scenario: Events arrive out of order

- **WHEN** a `page_view` beacon is delayed and arrives after the next one
- **THEN** the reconstructed order follows the sequence number, not the receipt time

A journey rebuilt from receipt timestamps is wrong exactly when it matters —
on slow connections, which are the ones worth knowing about.

### Requirement: A session MUST end by inactivity, and the timeout MUST be configured

A session ends after a configured period of inactivity (default 30 minutes, the
GA4 convention) and a new one begins on the next event.

#### Scenario: A visitor returns after the timeout

- **WHEN** a visitor's next event arrives after the inactivity window
- **THEN** it starts a new session with a new session id
- **AND** the previous session is closed at its last event, not at the new one

### Requirement: The client id MUST be first-party and portal-scoped

The client id is generated on the client, stored in first-party storage scoped
to the portal, and MUST NOT be derived from an IP address, a Nextcloud session,
or any identifier that exists outside the portal.

#### Scenario: The same browser visits two portals

- **WHEN** one browser visits two portals on different domains
- **THEN** it carries two unrelated client ids
- **AND** neither portal can tell the visits belong to one browser

### Requirement: An IP address MUST NOT be stored

The request IP MAY be used within the request to derive a coarse region and to
rate-limit. It MUST NOT be written to any event record, log line or aggregate.

#### Scenario: An event is stored

- **WHEN** an event is accepted
- **THEN** the stored record contains no IP address in any field
- **AND** the derived region is no finer than the level the portal configured

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
reach the portal — the same posture as the portal itself.

#### Scenario: A flood of events from one source

- **WHEN** events arrive faster than the configured rate
- **THEN** the excess is refused with a rate-limit response
- **AND** the refusal is counted, so a flood is distinguishable from quiet

#### Scenario: An oversized or malformed batch

- **WHEN** a batch exceeds the size limit or fails validation
- **THEN** it is refused whole, with a reason, and nothing partial is stored

### Requirement: The Traffic page MUST show what was measured, and say when it was not

#### Scenario: Measurement is disabled for a portal

- **WHEN** a portal has no measurement enabled
- **THEN** the Traffic page says so for that portal
- **AND** it does NOT render an empty chart

A zero and an unmeasured are different facts, and a chart showing zero for a
portal that was never instrumented is the more convincing of the two lies.
