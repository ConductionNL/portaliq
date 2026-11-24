---
status: proposed
---

# Spec: supplier-portal (white-label runtime config)

## MODIFIED Requirements

### Requirement: The portal MUST be organisation-agnostic and white-label

Portaliq SHALL resolve per-Organisation presentation (name, logo, theme,
IdP config placeholder, feature flags) **server-side, on every render of the
public portal shell**, from a tenant identifier carried on the unauthenticated
`/portal` request (e.g. `?org={slug}`), and expose it as
`window.RUNTIME_CONFIG` before the portal bundle boots. The shell SHALL
contain no organisation-specific literals (no "municipality"/"gemeente", no
hard-coded `theme`/`organisationName` defaults used in production). An unknown
or missing tenant identifier SHALL resolve to a safe neutral default — never
another tenant's configuration and never a server error.

#### Scenario: One build serves two organisations differently

- **GIVEN** two Organisations `O1` and `O2` with different name/logo/theme
- **WHEN** a supplier of each opens `/portal?org=O1` and `/portal?org=O2`
  respectively
- **THEN** each response's `window.RUNTIME_CONFIG` carries `O1`'s or `O2`'s own
  name, logo, and theme, with no rebuild

#### Scenario: Unknown tenant identifier degrades safely

- **GIVEN** a request to `/portal?org=does-not-exist` or `/portal` with no
  `org` parameter
- **WHEN** `PortalPageController::index()` resolves the tenant
- **THEN** the response renders with a neutral default `RUNTIME_CONFIG`
  (`organisationName: 'Portaliq'`, `theme: 'default'`) — not a 500, and not
  another tenant's data

### Requirement: The public portal's frame-ancestors policy MUST be per-tenant, default-denied

Portaliq's `#[PublicPage]` portal response SHALL set `Content-Security-Policy:
frame-ancestors` from the resolved Organisation's configured allowed embed
origins. When the Organisation has none configured, the policy SHALL be
`'none'` (not embeddable). The policy SHALL NOT be a wildcard (`*`) in any
configuration.

#### Scenario: Default tenant is not embeddable

- **GIVEN** an Organisation with no `allowedEmbedOrigins` configured
- **WHEN** a third-party site attempts to iframe `/portal?org={that org}`
- **THEN** the browser blocks the frame per `frame-ancestors 'none'`

#### Scenario: An explicitly configured tenant can be embedded

- **GIVEN** an Organisation with `allowedEmbedOrigins: ["https://example.org"]`
- **WHEN** `https://example.org` iframes `/portal?org={that org}`
- **THEN** the frame renders; any other origin attempting to iframe the same
  URL is blocked

## Notes

- **@e2e** covers `/portal?org=<tenant-a>` vs `/portal?org=<tenant-b>` rendering
  distinct branding (tasks.md 4.2).
