## 1. Tenant discovery

- [x] 1.1 Add `?org={slug}` handling to `PortalPageController::index()` /
      `catchAll()` (`lib/Controller/PortalPageController.php`); read
      `IRequest::getParam('org')`.
- [x] 1.2 Resolve the Organisation record from OpenRegister by slug (read-only,
      via OpenRegister's own `OrganisationMapper::findBySlug()` — ADR-022, no
      new parallel client; the same core entity every session's
      `organisation` claim already points at). Implemented in the new
      `PortalOrganisationConfigService`.
- [x] 1.3 Missing/unknown `org` → safe neutral default config
      (`organisationName: 'Portaliq'`, `theme: 'default'`), never a 500 and
      never another tenant's branding.

## 2. RUNTIME_CONFIG injection

- [x] 2.1 Build the `RUNTIME_CONFIG` array in `PortalPageController::index()`
      (name, logo, theme, `idp` placeholder, feature flags) from the resolved
      Organisation.
- [x] 2.2 Pass it to the `TemplateResponse` params and render it — via
      `IInitialStateService::provideInitialState()` in `templates/portal.php`
      (CSP-safe, no inline `<script>`/nonce plumbing needed — the app's
      default `ContentSecurityPolicy()` disallows inline scripts; this matches
      the same mechanism `templates/settings/admin.php` already uses for
      `version`), rather than a literal `window.RUNTIME_CONFIG` global as
      originally sketched.
- [x] 2.3 Updated `src/portal/main.jsx` to read the config via
      `loadState('portaliq', 'runtimeConfig', <dev-only default>)`; the
      comment states the fallback default object is dev-only.
- [x] 2.4 Confirmed `catchAll()` (deep links) renders through the same
      `index()` path so the resolved config is present on every portal URL,
      not just `/portal`.

## 3. CSP frame-ancestors

- [x] 3.1 Added a per-organisation `allowedEmbedOrigins: string[]` (default
      `[]`) override, stored in `IAppConfig` keyed by the Organisation's uuid
      (`PortalOrganisationConfigService`) rather than on OpenRegister's core
      `Organisation` entity itself (out of this app's boundary to extend).
- [x] 3.2 `PortalPageController::index()` builds `ContentSecurityPolicy` from
      the resolved Organisation's `allowedEmbedOrigins`;
      `addAllowedFrameAncestorDomain()` per configured origin, or `'none'`
      when the list is empty (`ContentSecurityPolicy()`'s own `'self'`
      default is explicitly cleared first) — removed the previous
      `addAllowedFrameAncestorDomain('*')`.
- [x] 3.3 Added a PHPUnit test (`PortalPageControllerTest`) asserting the CSP
      header for (a) no `org` param → `'none'`, (b) an Organisation with
      configured origins → those origins only, never `*`, never a residual
      `'self'`.

## 4. Tests + gates

- [x] 4.1 PHPUnit: `PortalPageController` + `PortalOrganisationConfigService`
      tests assert distinct resolved config for two different `org` slugs
      (the spec's "one build serves two organisations differently" scenario).
- [ ] 4.2 Playwright e2e: load `/portal?org=<tenant-a>` and
      `/portal?org=<tenant-b>` against a live instance with two seeded
      Organisations — DEFERRED, needs a running instance + seeded data; not
      run as part of this apply pass.
- [ ] 4.3 Run Hydra gates (spdx-headers, forbidden-patterns,
      route-reachability, spec-coverage) before push — not run as part of
      this apply pass (process/review step); flag for the PR review stage.

## Notes on scope taken

- The `?org=` value is the Organisation's `slug` (already a first-class field
  on OpenRegister's `Organisation` entity — `findBySlug()` exists), not a new
  identifier design.md left open-ended.
- Per-tenant presentation OVERRIDES (theme/logo/allowedEmbedOrigins/feature
  flags) are NOT stored on OpenRegister's `Organisation` entity (that would
  require an upstream OpenRegister schema change, out of portaliq's
  boundary) — they live in portaliq's own `IAppConfig`, keyed by the
  Organisation's uuid. No admin UI to author these overrides was built in
  this pass (out of scope); an operator sets them via `occ config:app:set` or
  a follow-up settings surface.
