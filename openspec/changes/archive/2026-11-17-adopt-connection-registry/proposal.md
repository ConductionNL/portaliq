---
kind: code
---

# Proposal: adopt-connection-registry

## Why

Portaliq talks to two kinds of outside systems, and an admin cannot see whether either works without reading a log.

- **The visitor geography database.** Portaliq downloads an offline database from DB-IP or MaxMind and turns a visitor's address into a country or region with it. A failed download, a MaxMind account without a licence key or a database file that no longer opens all leave regions empty, and only the log says so.
- **The login brokers.** Residents and suppliers sign in through DigiD, eHerkenning, eIDAS or a generic OIDC broker. Each portal organisation brings its own broker. When a broker stops answering, the only sign is a generic login error on the portal.

Hydra change `connection-registry` (hydra#667, amended in hydra#673, hydra#674 and hydra#676) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with two connections: `geo-db` and `oidc`.
- Both are `reportedOnly`. The geography provider is a three-way choice where `none` means off, not a mock, and an unset key means DB-IP. The brokers are configured per organisation, so one row stands for the whole family (design D12).
- The Visitor geography and Portal auth edge admin sections get stable ids: `section-visitor-geography` and `section-portal-auth-edge`.
- A geography settings save sends `ConnectionRefreshRequestedEvent` for `geo-db`, then reports what the saved settings and the installed database say.
- A geography refresh reports its outcome. It runs from the monthly job, the first-download job and `occ portaliq:traffic:geo-refresh`.
- A database file that cannot be opened reports an error, at most once per window.
- A broker discovery request and a code exchange report what the broker answered, at most once per window. No visitor request sends more than that.
- An Integrations page under the settings gear, over integriq's `app_connection` schema, preset to `app=portaliq`, admin only, and only shown when integriq is installed.
- Add integration opens `/apps/integriq/connections?app=portaliq&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, and the strings in English and Dutch.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D4, D6, D8, D9 and D12.
- integriq on `development`: the `app_connection` schema, the declaration sync, both events and the Connections overview.

Without integriq the menu entry is hidden, a deep link shows the missing-dependency screen, and nothing is sent.

## Out of scope

- Mail. Portaliq sends notification, task and report mail through Nextcloud's own mailer, which portaliq does not configure.
- The task gateway and the endpoint-action forward. Both call this same instance, not an outside system.
- The portal shared runtime (ADR-109). It is a front-end contract and makes no server-side call of its own.

## Rollback

Revert the change. Portaliq writes no rows of its own. Integriq removes the rows without a linked source on its next sync. The two report memory keys (`connection_report_geo-db`, `connection_report_oidc`) stay in app config and do nothing.
