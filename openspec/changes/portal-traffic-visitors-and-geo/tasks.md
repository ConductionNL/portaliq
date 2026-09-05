# Tasks: portal-traffic-visitors-and-geo

## 1. Geography

- [x] 1.1 `MmdbGeoResolver` over `maxmind-db/reader`, reading the database from the app data directory; country or `country-subdivision` per granularity; null when the file is absent or the address is unknown.
- [x] 1.2 `GeoDatabaseProvider` with `DbIpLiteProvider` (default, the current month's `dbip-city-lite` gzip, CC BY 4.0 attribution stored beside the file) and `MaxMindProvider` (basic auth account id + licence key, GeoLite2-City or GeoIP2-City).
- [x] 1.3 `GeoRefreshService`: download to a temporary file, open it to prove it is a database, move it into place, write the attribution.
- [x] 1.4 `occ portaliq:traffic:geo-refresh`, registered in `info.xml`; provider `none` says geography is disabled and exits 0.
- [x] 1.5 `TrafficGeoRefreshJob` (30 days, time insensitive) and a queued first download when a measuring portal wants a region and the file is absent, logged once, never inside the collector request.
- [x] 1.6 Settings: provider, MaxMind account id, licence key (sensitive, never echoed), edition; the admin panel section with the attribution and the database status.
- [x] 1.7 Tests: the resolver against the MaxMind test database (Apache-2.0, committed as a fixture), the providers against generated archives, the refresh service against a fake provider, the command against a fake service.

## 2. Visitors

- [x] 2.1 Rollup: `returningVisitors` and `newVisitors` from the client's `visitorType` hint where a client id exists, `null` for both in cookieless mode; `accounts` from distinct `userRef` when account linking is on.
- [x] 2.2 Ingest: `userRef` = the portal session's `subjectRef` when the portal switched on account linking and the request carries a valid bearer; absent otherwise.
- [x] 2.3 Client: `visitorType` on `session_start`; the bearer travels with the batch only for a portal that links accounts.
- [x] 2.4 Schema: `returningVisitors` and `accounts` on `portalTrafficDaily`, nullable `newVisitors`.

## 3. The Traffic page

- [x] 3.1 A date range in the report store (7, 30, 90 days, custom from and to) driving every widget.
- [x] 3.2 `TrafficVisitors` widget: visitors, new versus returning or "not available in cookieless mode", accounts when linked, ranked devices, browsers, operating systems, languages and regions.
- [x] 3.3 The Reports card opens the Traffic page (present since ADR-114; asserted here).

## 4. Documentation and proof

- [x] 4.1 `docs/operations/traffic-analytics.md`: providers, MaxMind configuration, DB-IP attribution, region granularity, what visitors means in cookieless mode, account linking.
- [x] 4.2 E2E on a throwaway instance with the fixture database installed: a country from a documented test address, nothing at granularity none, the range control, the Reports card, the disabled command.
