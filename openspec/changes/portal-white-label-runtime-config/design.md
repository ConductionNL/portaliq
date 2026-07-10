# Design: portal-white-label-runtime-config

## Problem

`PortalPageController::index()` renders the same static shell for every
visitor. `RUNTIME_CONFIG` needs to differ per Organisation, but the visitor is
**unauthenticated** at this point (no bearer, no `subjectRef`), so there is no
session claim to resolve an Organisation from. Something in the request must
identify the tenant before login.

## Options considered

1. **Path segment** — `/portal/{orgSlug}/...`. Requires reworking
   `appinfo/routes.php`'s `portalPage#index` / `portalPage#catchAll` routes
   (currently `/portal` and `/portal/{path}`) to carry an org slug segment, and
   reworking the React router's base path. Most explicit; easiest to reason
   about and to test; works identically behind any reverse proxy.
2. **Query parameter** — `/portal?org={orgSlug}`. No route changes. Lost on a
   plain bookmark to `/portal` without the query string. Simplest to ship.
3. **Subdomain** — `{org}.portal.example.com`. Requires DNS + reverse-proxy
   wildcard config per deployment; out of Portaliq's own control (Nextcloud
   itself is usually single-domain). Rejected for this slice — revisit only if
   a specific tenant needs it.

## Decision

Start with **(2) query parameter** (`?org={slug}`) for this change, because it
requires no routing rework and unblocks the MUST requirement fastest. Record
**(1) path segment** as the follow-up once real multi-tenant traffic exists
(a bookmarked `/portal` without `?org=` degrades to a neutral "choose your
organisation" / default-branded state rather than crashing — never leak
another tenant's config as a fallback default).

`orgSlug` resolves through OpenRegister's existing Organisation register (the
same one supplier/client sessions carry as their `organisation` claim) via a
plain read — no new parallel client, per ADR-022. Resolution failure (unknown
slug) renders the shell with the safe neutral default (`organisationName:
'Portaliq'`, `theme: 'default'`) and `frame-ancestors: 'none'`, never a 500 and
never another tenant's branding.

## CSP frame-ancestors

The Organisation record gains an optional `allowedEmbedOrigins: string[]`
field (empty by default). `PortalPageController::index()` builds the CSP from
that list; when empty, `frame-ancestors 'none'` (portal not embeddable — the
common case, since the eHerkenning/DigiD-authenticated portal is normally
opened directly, not iframed). This closes the current always-`*` gap while
still allowing an explicit tenant to opt into embedding.

## Non-goals

- Resolving `idp` (IdP config) beyond a placeholder passthrough — the real
  eHerkenning/DigiD broker wiring is `supplier-portal` T02/T03 (OpenConnector),
  out of scope here.
- Subdomain-based tenant routing (see Options, deferred).
