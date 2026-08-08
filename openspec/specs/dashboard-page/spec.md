---
example: true
capability: dashboard-page
status: example
built_by: openspec/changes/example-change
---

# Dashboard Page Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `portaliq` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Controller/DashboardController.php` in the template's own code, but the
> capability name, REQs, and scenarios are reference material — apps built from
> this template should replace this content with their own.

## Purpose

Serves the single-page application (SPA) that is the user-facing front end of
an OpenSpec-based Nextcloud app. The controller is deliberately thin: it
renders a Twig template that boots the Vue bundle, and it provides a
catch-all route so that the Vue router's history mode can resolve deep links
without hash (`#`) fragments.

## Requirements

### REQ-DASH-001: Render the main dashboard SPA entry point

The system MUST expose a public (no-admin-required, no-CSRF-token-required) HTTP endpoint that returns the Twig `index` template for the app. The template is responsible for loading the Vue bundle; the controller MUST NOT perform any business logic beyond template selection.

#### Scenario: Authenticated user opens the dashboard

- GIVEN a signed-in Nextcloud user navigates to `/apps/portaliq/`
- WHEN the request reaches `DashboardController::page()`
- THEN the system MUST return a `TemplateResponse` for template `index` under app id `portaliq`
- AND the response MUST be accessible without an admin role (`#[NoAdminRequired]`)
- AND the request MUST be accepted without a CSRF token (`#[NoCSRFRequired]`) so that first-request GETs work

### REQ-DASH-002: Catch-all route for Vue history-mode deep links

The system MUST expose a catch-all route that also returns the dashboard SPA so that any path under the app's URL root resolves to the same Vue bundle. This lets the Vue router's history mode (not hash mode — per ADR-004) own in-app routing, while the Nextcloud server still serves the SPA for every sub-path.

#### Scenario: Deep link to an in-app route

- GIVEN a user opens `/apps/portaliq/items/abc-123` directly (e.g. from an external link)
- WHEN the request reaches `DashboardController::catchAll()`
- THEN the system MUST return the same `TemplateResponse` as `page()`
- AND the Vue router MUST resolve the `/items/abc-123` path client-side after hydration
- AND the catch-all MUST be public in the same sense as `page()` (`#[NoAdminRequired]`, `#[NoCSRFRequired]`)

### REQ-DASH-003: The SPA shell mounts `CnAppRoot` from the bundled manifest

REQ-DASH-001/002 cover the server half of the SPA entry point. This requirement
covers its client half: `src/App.vue` is the Tier-4 app shell (ADR-024) and MUST
mount a single `<CnAppRoot>`, driven entirely by data rather than by hand-written
page markup.

The shell MUST forward, unchanged, the `manifest` it is given at bootstrap plus
the component registries `CnAppRoot` resolves widgets against — `customComponents`
(v1) and `registry` (the ADR-036 five-kind registry, v2). Both registries MUST be
accepted together, so a manifest may be migrated page by page rather than in one
cut-over.

The shell MUST also host exactly one `CnObjectSidebar` and expose the reactive
`objectSidebarState` channel via `provide()`, so that descendant `CnDetailPage`
instances drive that single sidebar instead of each rendering their own.

@e2e exclude structural shell contract, not a user-visible behaviour — what this
requires (one `<CnAppRoot>`, both registries forwarded, one hosted sidebar) is
asserted by inspecting the component, and any page-level browser test that
rendered at all would already depend on it being true; a dedicated e2e would
restate the precondition of every other e2e in this repo.

#### Scenario: Boot with a v2 manifest

- GIVEN `main.js` mounts `App` with a `manifest` prop and a `registry` prop
- WHEN the shell renders
- THEN it MUST render one `<CnAppRoot>` and pass `manifest`, `customComponents`,
  `pageTypes` and `registry` through unchanged
- AND it MUST NOT render page markup of its own beyond the named slots

#### Scenario: A detail page opens the object sidebar

- GIVEN a descendant `CnDetailPage` injects `objectSidebarState`
- WHEN it sets `active` to `true` and populates the object identifiers
- THEN the shell's single `<CnObjectSidebar>` MUST render with those values
- AND no second sidebar instance may be created for the same app shell
