---
kind: code
---

## Why

The supplier-portal spec's own **"The portal MUST be organisation-agnostic and
white-label"** requirement
(`openspec/changes/supplier-portal/specs/supplier-portal/spec.md:68-78`) says
Portaliq "SHALL resolve per-Organisation presentation (name, logo, theme, IdP
config, feature flags) at runtime from the tenant's OpenRegister Organisation
record and expose it as `window.RUNTIME_CONFIG`." Task T08 that was supposed to
deliver this is still unchecked in
`openspec/changes/supplier-portal/tasks.md:28`, and the code confirms it was
never done:

- `lib/Controller/PortalPageController.php:72-88` (`index()`) builds the
  `TemplateResponse` with an **empty** params array (`[]`) — it never resolves
  an Organisation or injects any config. The only mention of `RUNTIME_CONFIG` in
  the whole controller is a comment ("see T08") at line 62.
- `src/portal/main.jsx:14-20` reads `window.RUNTIME_CONFIG`, but since nothing
  ever sets that global, every tenant, in every environment, falls back to the
  hard-coded default: `{ organisationName: 'Portaliq', theme: 'utrecht',
  apiBase: '/index.php/apps/portaliq/portal/api', audience: 'supplier', idp:
  null }`.
- `templates/portal.php` (the PHP template `index()` renders) contains no
  `<script>` block or PHP interpolation of any config at all — just
  `Util::addScript` + the mount `<div>`.

Net effect: the spec's flagship scenario **"One build serves two organisations
differently"** (spec.md:75-78) cannot happen today — every supplier of every
Organisation sees the literal string "Portaliq" and the `utrecht` theme,
regardless of which tenant they were minted a session for. This is a MUST-level
requirement that is 0% implemented, not partially implemented.

A second, related gap in the same controller: `PortalPageController::index()`
(lib/Controller/PortalPageController.php:83-85) sets
`$csp->addAllowedFrameAncestorDomain('*')` — the portal explicitly allows
**any** origin to iframe it, with a comment "Tighten per-tenant in T08 if
needed." Because the portal SPA carries a bearer token and renders
authenticated actions (create-object buttons), an unrestricted frame-ancestors
policy is a clickjacking exposure for the same white-label surface T08 was
supposed to close. Fixing per-tenant `RUNTIME_CONFIG` resolution is also the
natural place to fix this: once Portaliq resolves the tenant's Organisation, it
can also resolve (and restrict to) that tenant's allowed embed origins instead
of `*`.

## What Changes

- `PortalPageController::index()` resolves the tenant's OpenRegister
  Organisation record (read-only, via OR's objects API per ADR-022 — no new
  parallel client) and injects it into the template as `window.RUNTIME_CONFIG =
  {...}` (name, logo, theme, idp config stub, feature flags), server-side,
  before the React bundle boots.
- Add a route/query mechanism for tenant discovery on the **unauthenticated**
  `/portal` page (a visitor has no bearer yet, so the Organisation cannot come
  from a session claim at this point) — e.g. a required `?org=` /ではsubdomain
  a subdomain, or path-segment identifier resolved to an Organisation record.
  Document the
  chosen mechanism in `design.md` since none exists in the codebase today (not
  even a TODO beyond the comment).
- CSP `frame-ancestors` is computed from the resolved Organisation's configured
  allowed embed origins (falling back to `'none'`, NOT `'*'`, when the tenant
  configures nothing) instead of the current hard-coded wildcard.
- `templates/portal.php` emits the resolved config as an inline `<script>`
  tag (escaped via `json_encode` + safe HTML-context encoding) before the
  portal bundle script tag.
- `src/portal/main.jsx`'s default fallback config is kept **only** for local
  dev without a backend; document that in production `window.RUNTIME_CONFIG`
  is always present.

**BREAKING**: any current deployment relying on the current permissive
`frame-ancestors: *` to embed the portal in an arbitrary site will need to
configure an explicit allowed origin on the Organisation record afterward.

## Capabilities

### Modified Capabilities
- `supplier-portal`: the **"portal MUST be organisation-agnostic and
  white-label"** requirement moves from unimplemented to implemented; the CSP
  frame-ancestors behavior is tightened from "any origin" to "per-tenant
  configured origins, default none."

## Impact

- `lib/Controller/PortalPageController.php` — resolve Organisation, build
  `RUNTIME_CONFIG`, tighten CSP.
- `templates/portal.php` — emit the config script tag.
- `src/portal/main.jsx` — no functional change, comment update only.
- New: a tenant-discovery mechanism for the unauthenticated `/portal` entry
  point (see design.md).
