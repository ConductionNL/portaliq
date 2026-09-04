# Spec: portal-traffic-visitors-and-geo

## ADDED Requirements

### Requirement: Geography MUST come from an offline database the operator chose

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

The licence key is stored as a sensitive app config value. A settings read
says only whether a key is stored. The account id is shown to
administrators only.

#### Scenario: A settings read says a key is stored, not what it is

- **WHEN** an administrator saved a MaxMind licence key and reads the settings
- **THEN** the response says a key is stored and carries no field with its value

### Requirement: Visitors MUST be counted honestly in each mode

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

The Visitors widget shows the visitors in the range, new versus returning
where known and the words "not available in cookieless mode" where not, the
distinct accounts when the portal links them, and ranked lists of device
types, browsers, operating systems, languages and regions. A dimension the
portal did not enable shows as not measured rather than as an empty list.

#### Scenario: Regions are listed from the rollups

- **WHEN** a rollup in the range carries `regions.GB`
- **THEN** the Visitors widget lists GB with its count
