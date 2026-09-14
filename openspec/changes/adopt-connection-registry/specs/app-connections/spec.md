# app-connections Specification Delta

**Status**: proposed
**Scope**: portaliq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see portaliq's outside connections on one page, with a status portaliq can back.

## ADDED Requirements

### Requirement: REQ-PORTALIQ-CONN-001 Portaliq declares its connections in one static file

Portaliq SHALL declare `geo-db` and `oidc` in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The file SHALL validate against integriq's `connections.schema.json`. Both entries SHALL be `reportedOnly` and SHALL carry no `adapter` and no `requiredConfig`, because `none` switches geography off rather than faking it and the brokers are configured per organisation. Every `settingsUrl` SHALL point at an element id that exists in the admin settings panel.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php validates it against the vendored schema, and checks the app id, unique keys and anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `#section-…` anchor SHALL be an id in `src/views/AdminRoot.vue`

#### Scenario: Neither row is read as simulated
@e2e exclude The rule lives in integriq's resolver; tests/Unit/Settings/ConnectionsDeclarationTest.php asserts that neither entry declares an adapter.

- **GIVEN** integriq has synced portaliq's declaration
- **WHEN** `traffic.geo.provider` holds `none` or is unset
- **THEN** integriq's rule 3 SHALL NOT apply to the `geo-db` row

### Requirement: REQ-PORTALIQ-CONN-002 A geography save refreshes, and a refresh or a failed open reports

When a settings save writes `traffic_geo`, portaliq SHALL send `ConnectionRefreshRequestedEvent` with app `portaliq` and key `geo-db`, and SHALL send it before the report for that key (hydra REQ-CONN-004, hydra#674). The report SHALL say `unconfigured` for provider `none`, for MaxMind without an account id or licence key, and for a missing database; `limited` when the installed database came from the other provider; and `configured` otherwise. A refresh SHALL report `refreshed` as `configured`, `failed` as `error` and `disabled` as `unconfigured`. A database file that cannot be opened SHALL report `error` without its path, at most once per throttle window. Both events SHALL be named by string and sent only when the class exists, and neither SHALL change the response of the request, job or command that sent it.

#### Scenario: Saving geography settings refreshes, then reports
@e2e exclude The event is not observable from a browser; tests/Unit/Service/Connection/ConnectionReporterTest.php and tests/Unit/Service/SettingsServiceConnectionRefreshTest.php assert the order and the unchanged response.

- **GIVEN** integriq is installed
- **WHEN** an admin saves the geography settings with provider `none`
- **THEN** portaliq SHALL send a refresh for `geo-db`
- **AND** then a report `unconfigured` saying geography is switched off

#### Scenario: A failed refresh reads error
@e2e exclude A refresh downloads from DB-IP or MaxMind, which the CI instance does not reach; tests/Unit/Service/Connection/ConnectionReporterTest.php and tests/Unit/Service/Traffic/Geo/GeoRefreshConnectionReportTest.php drive the outcomes.

- **GIVEN** provider `dbip`
- **WHEN** the refresh job cannot download the database
- **THEN** portaliq SHALL report `geo-db` as `error` with the reason

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts nothing is sent, stored or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** an admin saves geography settings, or a refresh runs
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the save or refresh SHALL answer as it did before this change

### Requirement: REQ-PORTALIQ-CONN-003 A broker call reports what the broker answered, throttled

A discovery request that fails, and a code exchange, SHALL report the `oidc` row from what the broker answered. A token response SHALL read `configured`. No answer, a 5xx, a refused client and a response without the needed endpoints or token SHALL read `error`, naming the broker by host only. Any other 4xx SHALL report nothing. A report from these calls SHALL go out only when the last report for the key is an hour old with the same status, or five minutes old with a different one. No visitor request SHALL send more than one report per window.

#### Scenario: A broker that stops answering reads error
@e2e exclude A real broker login needs a DigiD, eHerkenning or OIDC test broker, which the CI instance does not have; tests/Unit/Service/OidcClientConnectionReportTest.php and tests/Unit/Service/Connection/ConnectionReporterTest.php cover it.

- **GIVEN** an organisation with a configured broker
- **WHEN** the broker's discovery request times out
- **THEN** portaliq SHALL report `oidc` as `error` with the broker's host
- **AND** the message SHALL NOT carry the path or query of the broker URL

#### Scenario: A code that expired reports nothing
@e2e exclude Same reason as above; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts no event for invalid_grant.

- **GIVEN** a resident whose authorization code expired
- **WHEN** the broker answers the code exchange with 400 `invalid_grant`
- **THEN** portaliq SHALL NOT report the `oidc` row

#### Scenario: A busy login page reports once per window
@e2e exclude The window is an hour; tests/Unit/Service/Connection/ConnectionReporterTest.php drives the clock.

- **GIVEN** a report `configured` for `oidc` two minutes ago
- **WHEN** fifty more logins complete
- **THEN** portaliq SHALL send no further report until an hour has passed

### Requirement: REQ-PORTALIQ-CONN-004 An admin reads the connections on an Integrations page

Portaliq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `portaliq` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. The status column SHALL name all six statuses, `limited` included. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=portaliq&link=1`.

#### Scenario: The page lists only the rows of portaliq
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** portaliq and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations page
- **THEN** the page SHALL list the two declared connections
- **AND** every listed row SHALL have `app` equal to `portaliq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=portaliq` and `link=1`

#### Scenario: A geography save shows on the page
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the geography provider is not `none`
- **WHEN** the admin saves the provider `none`
- **THEN** the Visitor geography database row SHALL read `unconfigured` with the switched-off message

#### Scenario: A connection that works in part reads Limited
@e2e exclude Only a provider switch before the next download produces limited; tests/connection-registry.spec.mjs asserts the label in English and Dutch.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
