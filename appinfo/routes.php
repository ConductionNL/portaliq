<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        // Canonical settings write per OpenRegister's AppHost dialect
        // (Routes::standard()): PUT is the write, POST above is the legacy
        // alias. Portaliq does not call Routes::standard(), so this entry has
        // to be declared locally — without it PUT /api/settings answers 405.
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Headless content API (ADR-086 §1) — the CMS contract. Public and
        // read-only: a Docusaurus build or any third-party front-end reads
        // these with no Nextcloud session, which is what makes the CMS
        // headless rather than a portal with an API attached. Portaliq's own
        // built-in portal reads exactly the same endpoints and gets no
        // privileged path of its own.
        //
        // Registered BEFORE /portal so none of them is swallowed by the SPA
        // catch-all. The page route is a catch-all over the rest of the path,
        // because an in-site route is arbitrary depth ('/beleid/2026/woo').
        ['name' => 'content#site', 'url' => '/api/content/site', 'verb' => 'GET'],
        ['name' => 'content#menus', 'url' => '/api/content/menus', 'verb' => 'GET'],
        ['name' => 'content#pages', 'url' => '/api/content/pages', 'verb' => 'GET'],
        ['name' => 'content#glossary', 'url' => '/api/content/glossary', 'verb' => 'GET'],
        // The contribution bridge (ADR-046 + ADR-086 §1). Anonymous surfaces
        // only; a visitor with a session reads their own aggregate through
        // `/api/contributions`, which is subject-scoped and never cacheable.
        ['name' => 'content#contributions', 'url' => '/api/content/contributions', 'verb' => 'GET'],
        ['name' => 'content#page', 'url' => '/api/content/page', 'verb' => 'GET'],
        [
            'name' => 'content#page',
            'url' => '/api/content/page/{route}',
            'verb' => 'GET',
            'requirements' => ['route' => '.+'],
            'postfix' => 'byroute',
        ],

        // Public portal SPA (external clients + suppliers) — served with public
        // chrome via #[PublicPage]. The portalPage#catchAll route handles
        // client-side deep links. Registered BEFORE the dashboard catch-all so
        // /portal is not swallowed by /{path}.
        ['name' => 'portalPage#index', 'url' => '/portal', 'verb' => 'GET'],

        // The built-in SITE renderer (ADR-084) — the Vue replacement for the
        // React portal above. Served alongside it while parity is being
        // measured: a comparison against a portal that has already been
        // deleted is not a comparison. `/site` retires `/portal` once the
        // control pair is recorded, not before.
        ['name' => 'portalPage#site', 'url' => '/site', 'verb' => 'GET'],

        // Portal auth-edge API (supplier-portal T02). session#index resolves the
        // caller's bearer (fail-closed); devLogin is debug-gated; logout ends the
        // client session. Registered before the /portal/{path} SPA catch-all.
        ['name' => 'session#index', 'url' => '/portal/api/session', 'verb' => 'GET'],
        ['name' => 'session#devLogin', 'url' => '/portal/api/session/dev-login', 'verb' => 'POST'],
        ['name' => 'session#logout', 'url' => '/portal/api/session', 'verb' => 'DELETE'],
        // Sliding-window session refresh, capped by an absolute maximum
        // session lifetime (portal-session-hardening-v2 T03). Registered
        // before the /portal/{path} SPA catch-all.
        ['name' => 'session#refresh', 'url' => '/portal/api/session/refresh', 'verb' => 'POST'],
        // Generic, broker-agnostic OIDC Relying Party (portal-oidc-broker-login,
        // T06/T07): start builds a state+nonce+PKCE authorization request and
        // 302s to the broker; callback validates the ID token and mints the
        // existing HS256 portal session. Registered before the /portal/{path}
        // SPA catch-all.
        ['name' => 'session#oidcStart', 'url' => '/portal/api/session/oidc/start', 'verb' => 'GET'],
        ['name' => 'session#oidcCallback', 'url' => '/portal/api/session/oidc/callback', 'verb' => 'GET'],

        // Admin-only incident response (portal-auth-edge-session-hardening):
        // revoke every active portal session for an Organisation.
        ['name' => 'sessionAdmin#revokeOrganisation', 'url' => '/api/session-admin/revoke-organisation', 'verb' => 'POST'],

        // Aggregated portal contributions for the authenticated subject
        // (supplier-portal T04). Guarded by PortalAuthMiddleware (fail-closed).
        // The response also carries the subject's own unread inbox count
        // (portal-inbox-v2 T04).
        ['name' => 'contribution#index', 'url' => '/portal/api/contributions', 'verb' => 'GET'],
        // Unified inbox — merges every `kind: inbox` collection across the
        // subject's contributions, sorted by receivedAt desc, provenance-tagged
        // (portal-inbox-v2 T02). Registered before the /portal/{path} catch-all.
        ['name' => 'contribution#inbox', 'url' => '/portal/api/inbox', 'verb' => 'GET'],
        // Tamper-proof mark-read on ONE inbox message: ownership/tenant/trust
        // re-verified before any write; only `read` is ever set (portal-inbox-v2
        // T03). The {register}/{schema}/{id} segments distinguish it from the
        // plain GET above.
        ['name' => 'contribution#markRead', 'url' => '/portal/api/inbox/{register}/{schema}/{id}/read', 'verb' => 'PATCH'],
        // Objects in one contribution collection, subject-scoped (T05).
        ['name' => 'contribution#collection', 'url' => '/portal/api/collections/{register}/{schema}', 'verb' => 'GET'],
        // Create an object in a collection, owned by the subject (T06).
        ['name' => 'contribution#create', 'url' => '/portal/api/collections/{register}/{schema}', 'verb' => 'POST'],
        // Read/update a SINGLE object, subject-scoped with per-row ownership
        // re-verification (portal-scoped-crud, ADR-062 Phase 1; closes #16).
        // Registered before the /portal/{path} SPA catch-all; the {id} segment
        // makes these distinct from the collection-level routes above.
        ['name' => 'contribution#object', 'url' => '/portal/api/collections/{register}/{schema}/{id}', 'verb' => 'GET'],
        ['name' => 'contribution#update', 'url' => '/portal/api/collections/{register}/{schema}/{id}', 'verb' => 'PATCH'],
        // Attach an uploaded file to an owned object (the file-upload block,
        // ADR-063). Ownership re-verified via the scoped reader; the collection
        // must declare `filesUpload: true`.
        ['name' => 'contribution#uploadFile', 'url' => '/portal/api/collections/{register}/{schema}/{id}/files', 'verb' => 'POST'],
        // Stream a file attached to an owned object (portal-document-download,
        // the read-side counterpart of uploadFile). Ownership re-verified via
        // the scoped reader BEFORE the file is resolved; the collection must
        // declare `filesDownload: true`. Registered before the /portal/{path}
        // catch-all; the {fileId} segment makes this distinct from the upload route.
        ['name' => 'contribution#downloadFile', 'url' => '/portal/api/collections/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'GET'],
        // Schema definition by slug (gated to the subject's manifest) for the
        // schema-driven frontend engine (ADR-063). The store fetches a schema by
        // slug; the adapter maps /openregister/api/schemas/{slug} here.
        ['name' => 'contribution#schema', 'url' => '/portal/api/schema/{schema}', 'verb' => 'GET'],
        // Forward a declared endpoint action server-to-server with a signed
        // X-Portal-Subject assertion (contract-v2 T8, ADR-046 A6). Guarded by
        // PortalAuthMiddleware; registered before the /portal/{path} catch-all.
        ['name' => 'contribution#action', 'url' => '/portal/api/actions/{appId}/{actionId}', 'verb' => 'POST'],

        ['name' => 'portalPage#catchAll', 'url' => '/portal/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],

        // Hosted tilburg-woo-ui (Open Tilburg WOO SPA) — public. Registered
        // BEFORE the greedy /{path} catch-all so /woo assets are not swallowed.
        ['name' => 'woo#serve', 'url' => '/woo', 'verb' => 'GET'],
        ['name' => 'woo#servePath', 'url' => '/woo/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
