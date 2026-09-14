# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673, hydra#674 and hydra#676). This file records how portaliq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development` on 2026-09-14.

| Key | Declared as | Why |
|---|---|---|
| `geo-db` | `reportedOnly: true`, links to `#section-visitor-geography` | `GeoRefreshService` downloads from DB-IP or MaxMind, and `MmdbGeoResolver` opens the file on a traffic request. |
| `oidc` | `reportedOnly: true`, links to `#section-portal-auth-edge` | `OidcClientService` calls each organisation's broker for discovery, the code exchange and the signing keys. |

**Why the geography row is not an adapter row.** `traffic.geo.provider` holds `none`, `dbip` or `maxmind`. `none` switches geography off: no database is fetched and no region is stored. Nothing fakes an answer, so a Simulated status would be false. `NullGeoResolver` exists for tests and is not bound. An unset key reads as `dbip` (`GeoSettings::DEFAULT_PROVIDER`), so the contract default `simulatedValues: [""]` would call the default provider simulated.

**Why the geography row has no `requiredConfig`.** DB-IP needs no setting at all, and MaxMind needs an account id and a licence key. What decides whether regions work is the installed database file, which integriq cannot see. No fixed key list says "configured".

**Why one broker row.** Broker settings live per organisation, in the `org_presentation_{uuid}` blob and a sensitive secret key per organisation and provider. A static file cannot list them (design D12, "Still out"). One row stands for the family and carries the last outcome of any organisation.

**Why the broker row links to the Portal auth edge section.** No admin section edits the broker settings. The auth edge section is where an admin sees whether the portal can issue sessions at all, which is the step every broker login ends in, and where sessions of one organisation are revoked. The declared `unconfiguredMessage` names where the broker settings live.

**What was left out.** Mail goes through Nextcloud's `IMailer`, which portaliq does not configure. `PortalTaskGateway` and `PortalActionForwarder` call routes on this same instance. The portal shared runtime (ADR-109) is a front-end contract with no server-side call. None of them is an outside connection portaliq owns.

## D2. What portaliq reports, and when

`lib/Service/Connection/ConnectionReporter.php` sends both events. It names the classes by string behind `class_exists` (ADR-041) and never throws. `lib/Service/Connection/ConnectionObservations.php` maps an outcome to a status and a message, and holds no state.

**Geography, on a settings save** (`PUT` or `POST /api/settings` with `traffic_geo`). The reporter sends the refresh first and the report second, because under hydra#674 a refresh retires older observations. It reads `GeoSettings::toArray()` and `GeoRefreshService::status()`.

| Portaliq sees | Status | Message |
|---|---|---|
| Provider `none` | `unconfigured` | "Geography is switched off. No database is fetched and no region is stored." |
| MaxMind without an account id or licence key | `unconfigured` | names both settings |
| No database installed | `unconfigured` | names `occ portaliq:traffic:geo-refresh` |
| The installed database came from the other provider | `limited` | names the provider regions still come from |
| A database from the chosen provider | `configured` | names the provider and when it was fetched |

**Geography, after a refresh** (the monthly job, the first-download job, or `occ portaliq:traffic:geo-refresh`). `refreshed` reads `configured`, `failed` reads `error` with the reason cut at 160 characters, and `disabled` reads `unconfigured`.

**Geography, when the file will not open.** `MmdbGeoResolver` opens the database once per process. When that throws, the reporter sends `error`. The message leaves out the file path.

**Brokers, on discovery.** A discovery request that throws, or answers without the three endpoints a login needs, reads `error`. A cached discovery document sends nothing, because no call was made.

**Brokers, on the code exchange.**

| The broker answered | Status |
|---|---|
| 2xx with a token response | `configured` |
| 2xx without a token response | `error` |
| no answer | `error` |
| 5xx | `error`, naming the HTTP status |
| 401, 403, or `invalid_client` or `unauthorized_client` | `error`, naming this portal's client credentials |
| any other 4xx, such as `invalid_grant` | nothing |

`invalid_grant` is about one login, such as a code that expired while the resident waited, so it says nothing about the broker. A message names the broker by host only, never by path or query.

**Throttle.** Every report remembers its status and time in app config under `connection_report_{key}`. A report from a visitor request (a failed open, discovery, the code exchange) goes out when the last one is an hour old with the same status, or five minutes old with a different one. Two organisations whose brokers disagree therefore cannot report on every login. A save forgets that memory and reports at once. A refresh job reports at once too, because it runs once a month. Both still record the memory, so the next visitor request compares against them.

**Why this is cheap.** A save and `occ` are admin actions, and the refresh job runs monthly. On a visitor request the throttle costs one app-config read, which Nextcloud already holds in memory, and at most one write per window. Login routes carry `AnonRateLimit(30/60)` on top.

**Wiring.** `SettingsService`, `GeoRefreshService`, `MmdbGeoResolver` and `OidcClientService` are autowired, so each takes the reporter as an optional last argument. `Application.php` builds none of them by hand.

## D3. The page

- `src/manifest.json` gains an `index` page `Integrations` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`, and the columns connection, status, status message, last checked and settings.
- Its menu entry `IntegrationsMenu` sits in the settings gear with `query: {app: portaliq}`, `permission: admin` and `visibleIf.appInstalled: integriq`.
- `src/lib/connectionRegistry.js` holds the two formatters and `openIntegriqConnections`. It imports nothing, so `tests/connection-registry.spec.mjs` runs it under plain node, the way `openPortalSite.js` is tested.
- `App.vue` passes the formatters through CnAppRoot's `formatters` prop. It passed none before this change. `src/customComponents.js` carries the handler, because the manifest action dispatcher resolves a handler name against that map only.

**Formatters.** The installed `@conduction/nextcloud-vue` 2.40.0 ships no `connectionStatus` built-in, so portaliq carries a local copy with all six labels, `limited` included.

## D4. Contract misfits

- **A provider choice where the default is real.** `adapter.simulatedValues` can name `none`, but `none` is off, not a mock, and an unset key is the DB-IP default. The contract has no status for "switched off by choice" apart from `unconfigured`, which reads as a task for the admin.
- **What decides "configured" is a file, not a setting.** Rule 5 reads app config only. The geography row needs the installed database, which only portaliq can see.
- **A family of brokers.** The contract names per-portal OIDC as out (D12). One row carries the last outcome of any organisation, so a broken broker for one organisation can be overwritten by a working one for another after five minutes.
- **No settings section for the brokers.** `settingsUrl` points at the nearest section that exists, and the declared message says where the settings live.

## Risks

- **Same-second ordering.** Portaliq sends the refresh before the report. Hydra#674 compares with "not older than", so an equal stamp counts.
- **A family row can hide one organisation's failure.** The message names the broker host, so an admin who reads an error sees which broker failed, and a later success from another host replaces it.
