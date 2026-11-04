# portal-traffic-visitors-and-geo Specification

## Purpose

Visitors are counted per day and placed in a region through an offline geography database the operator chose, with account linking only as a pseudonymous reference. This spec fixes how a visitor and a session are recognised without storing an IP address, the database providers and their refresh, honest visitor counts per measurement mode, the date range and the Visitors widget.

## Requirements

### Requirement: Geography MUST come from an offline database the operator chose

Geography MUST come from an offline database the operator chose.

The region on an event is derived from the visitor's address by a lookup in
a database file held in the app's data directory, and the address is then
discarded. The operator chooses the provider in the app settings: `none`
(no geography for any portal), `dbip` (DB-IP's free city database, CC BY
4.0, the default) or `maxmind` (GeoLite2-City or GeoIP2-City with an account
id and a licence key). The lookup obeys each portal's `regionGranularity`:
`country` stores the ISO 3166-1 alpha-2 code, `region` stores
`<country>-<subdivision>`, `none` stores nothing. No address is ever sent to
a third party to be resolved.

#### Scenario: A country is derived from a documented test address

- **WHEN** a measuring portal has `regionGranularity: country` and the `region` dimension, the database is present, and an event arrives from `81.2.69.160`
- **THEN** the stored event carries `region: GB` and no address
- **AND** the day's rollup counts the session under `regions.GB`

#### Scenario: Nothing is stored at granularity none

- **WHEN** a portal has `regionGranularity: none`
- **THEN** the stored event carries no `region`, whatever the database knows

#### Scenario: A subdivision is stored at granularity region

- **WHEN** a portal has `regionGranularity: region` and the database knows the subdivision
- **THEN** the stored event carries `<country>-<subdivision>`, such as `GB-ENG`
- **AND** a database that knows only the country stores the country alone

@e2e exclude covered by MmdbGeoResolverTest against the MaxMind test database; the throwaway instance's seeded portals stay at country

#### Scenario: An absent database resolves nothing and never blocks the collector

- **WHEN** the database file is absent or the provider is `none`
- **THEN** the resolver answers null, the event is stored without a region, and the request does not wait for a download

@e2e exclude covered by MmdbGeoResolverTest; a network download inside an e2e would measure the rig, not the app

### Requirement: The geography database MUST be refreshed without an operator, and on demand

The geography database MUST be refreshed without an operator, and on demand.

A monthly background job refreshes the database from the configured
provider. `occ portaliq:traffic:geo-refresh` does the same on demand. The
first download is queued, once, when a measuring portal wants a region and
no database exists; it runs in the background, never inside the collector
request. Every refresh downloads to a temporary file, opens it to prove it is
a database, and only then replaces the file in use; the provider's
attribution is stored beside it.

#### Scenario: The command with provider none says so and exits 0

- **WHEN** the provider setting is `none` and the command runs
- **THEN** it prints that geography is disabled and exits 0, downloading nothing

#### Scenario: A refresh replaces the file only after it opened

- **WHEN** a download completes
- **THEN** the file is opened as a database before it replaces the one in use
- **AND** a download that does not open leaves the previous database in place and reports the failure

@e2e exclude covered by GeoRefreshServiceTest with a fake provider; the real providers need outbound network the rig does not promise

#### Scenario: The first download is queued, not performed in the request

- **WHEN** a measuring portal with a region granularity other than none receives its first event and no database exists
- **THEN** a one-off refresh job is queued once and logged once
- **AND** the event is stored without a region

@e2e exclude covered by MmdbGeoResolverTest and GeoRefreshServiceTest; the queued job's download needs outbound network

### Requirement: MaxMind credentials MUST never be echoed back

MaxMind credentials MUST never be echoed back.

The licence key is stored as a sensitive app config value. A settings read
says only whether a key is stored. The account id is shown to
administrators only.

#### Scenario: A settings read says a key is stored, not what it is

- **WHEN** an administrator saved a MaxMind licence key and reads the settings
- **THEN** the response says a key is stored and carries no field with its value

### Requirement: Visitors MUST be counted honestly in each mode

Visitors MUST be counted honestly in each mode.

`visitors` is the number of distinct visitors on the day: distinct daily
hashes in cookieless mode, distinct client ids where the portal persists one.
`newVisitors` and `returningVisitors` are counted only among visitors with a
persisted client id, from the client's `visitorType` hint on
`session_start`; in cookieless mode both are `null` (which the object API
returns as an absent field), never zero, because a hash that does not
survive the day cannot say whether it was here yesterday.

#### Scenario: Cookieless returning visitors are not available

- **WHEN** a portal does not persist a client id and the day is rolled up
- **THEN** the rollup carries `visitors` as a count and `returningVisitors` as null
- **AND** the Traffic page says new versus returning is not available in cookieless mode

#### Scenario: Persisted ids count new and returning

- **WHEN** a portal persists a client id and two sessions start, one with `visitorType: new` and one with `visitorType: returning`
- **THEN** the rollup counts one new and one returning visitor

@e2e exclude covered by TrafficRollupTest; the seeded portals are cookieless by design and the site record test asserts it

### Requirement: Account linking MUST attach only a pseudonymous reference

Account linking MUST attach only a pseudonymous reference.

When a portal switched on `sensitive.accountLinking` and the request carries
a valid portal session bearer, the stored event carries `userRef` = the
portal account's `subjectRef`. It never carries a BSN, a KVK number, an
email address or a display name. Without the switch, or without a valid
bearer, the field is absent. The day's rollup counts distinct references as
`accounts`, only when the switch is on.

#### Scenario: A linked portal stores the subject reference

- **WHEN** account linking is on and the batch carries a valid bearer
- **THEN** each stored event carries `userRef` equal to the session's `subjectRef` and nothing else about the person

@e2e exclude covered by TrafficIngestServiceTest and TrafficControllerTest; minting a portal session needs the signing secret the rig does not configure

#### Scenario: Without the switch the bearer is ignored

- **WHEN** account linking is off and the batch carries a valid bearer
- **THEN** the stored event carries no `userRef`

@e2e exclude covered by TrafficIngestServiceTest; same reason as the scenario above

### Requirement: The Traffic page MUST let the reader choose the range

The Traffic page MUST let the reader choose the range.

One date range, shared by every widget on the page: the last 7, 30 or 90
days, or a custom start and end. Changing it changes every number on the
page.

#### Scenario: The range changes the numbers

- **WHEN** events exist on two different days and the reader narrows the range to one of them
- **THEN** the page view count changes to that day's alone
- **AND** widening it again restores the total

#### Scenario: The Reports card opens the page

- **WHEN** the reader opens Reports and picks the Traffic card
- **THEN** the Traffic page opens with the overview widget

### Requirement: The Visitors widget MUST show who visited without inventing a number

The Visitors widget MUST show who visited without inventing a number.

The Visitors widget shows the visitors in the range, new versus returning
where known and the words "not available in cookieless mode" where not, the
distinct accounts when the portal links them, and ranked lists of device
types, browsers, operating systems, languages and regions. A dimension the
portal did not enable shows as not measured rather than as an empty list.

#### Scenario: Regions are listed from the rollups

- **WHEN** a rollup in the range carries `regions.GB`
- **THEN** the Visitors widget lists GB with its count

### Requirement: A session MUST be reconstructable into an ordered journey

A session MUST be reconstructable into an ordered journey.

Events carry a monotonically increasing sequence number within the session,
so the path from page to page can be reconstructed without relying on arrival
order or server clocks. A session id is carried when the client keeps one;
otherwise the aggregation step derives the session from the visitor hash and
the portal's inactivity window.

#### Scenario: Two pages viewed in order
@e2e exclude covered by TrafficSessioniserTest::testACookielessVisitorsEventsFormOneOrderedSession
- **WHEN** a visitor views `/` and then `/begrippen` in one session
- **THEN** the two events share a session (explicit id, or the same visitor hash within the window) and carry increasing sequence numbers
- **AND** the entrance, the exit and the transition between them are derivable

#### Scenario: Events arrive out of order
@e2e exclude a delayed beacon cannot be staged reliably in a browser; covered by TrafficSessioniserTest::testAnExplicitSessionIsOrderedBySequenceNotByReceipt
- **WHEN** a `page_view` beacon is delayed and arrives after the next one
- **THEN** the reconstructed order follows the sequence number, not the receipt time

A journey rebuilt from receipt timestamps is wrong exactly when it matters,
on slow connections, which are the ones worth knowing about.

### Requirement: A session MUST end by inactivity, and the timeout MUST be configured

A session MUST end by inactivity, and the timeout MUST be configured.

A session ends after a configured period of inactivity (default 30 minutes, the
GA4 convention) and a new one begins on the next event.

#### Scenario: A visitor returns after the timeout
@e2e exclude waiting out a 30 minute window does not fit an e2e run; covered by TrafficSessioniserTest::testAReturnAfterTheTimeoutStartsANewSession
- **WHEN** a visitor's next event arrives after the inactivity window
- **THEN** it starts a new session with a new session id
- **AND** the previous session is closed at its last event, not at the new one

### Requirement: Measurement MUST be cookieless unless the portal persists a client id

Measurement MUST be cookieless unless the portal persists a client id.

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
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::the-default-mode-stores-nothing-in-the-browser`
- **WHEN** a portal measures traffic with the default configuration
- **THEN** the served client sends page views and stores no cookie, no local storage key and no session storage key
- **AND** the stored event carries a visitor hash and no client id

#### Scenario: A stale client sends an id the portal does not persist
@e2e exclude covered by TrafficEventValidatorTest::testAClientIdIsDroppedUnlessThePortalPersistsIds
- **WHEN** an event carries a `clientId` for a portal whose `persistClientId` is off
- **THEN** the event is stored without it

#### Scenario: The same browser visits two portals
@e2e exclude two portal domains need a second host the rig does not serve; the client keeps its id in localStorage, which a browser scopes to the origin
- **WHEN** one browser visits two portals on different domains, both persisting ids
- **THEN** it carries two unrelated client ids
- **AND** neither portal can tell the visits belong to one browser

### Requirement: Derived dimensions MUST be computed on the server and never accepted from the client

Derived dimensions MUST be computed on the server and never accepted from the client.

Device type, browser, operating system, language, region, referrer host,
channel and the campaign fields are derived from the request and the page
location. The address and the user agent are discarded in the same request.
A client that posts one of these fields directly has it ignored.

#### Scenario: A client posts a region
@e2e exclude covered by TrafficEventValidatorTest::testADerivedDimensionFromTheClientIsIgnored
- **WHEN** an event carries `region` from the client
- **THEN** the stored record's region is the one derived on the server, or empty

### Requirement: An IP address MUST NOT be stored

An IP address MUST NOT be stored.

The request IP MAY be used within the request to derive a coarse region and to
rate-limit. It MUST NOT be written to any event record, log line or aggregate.

#### Scenario: An event is stored
@e2e exclude stored fields are not visible in a browser; covered by TrafficIngestServiceTest::testAPageViewIsStoredWithoutTheAddressOrTheAgent
- **WHEN** an event is accepted
- **THEN** the stored record contains no IP address and no raw user agent in any field
- **AND** the derived region is no finer than the level the portal configured

#### Scenario: A bot is not a visitor
@e2e exclude covered by tests/e2e/traffic-analytics.spec.ts, still tagged `portal-traffic-analytics::a-bot-is-not-a-visitor`
- **WHEN** the user agent identifies a crawler
- **THEN** the event is refused with the reason `bot`, and counted
