<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Public portal SPA (external clients + suppliers) — served with public
        // chrome via #[PublicPage]. The portalPage#catchAll route handles
        // client-side deep links. Registered BEFORE the dashboard catch-all so
        // /portal is not swallowed by /{path}.
        ['name' => 'portalPage#index', 'url' => '/portal', 'verb' => 'GET'],

        // Portal auth-edge API (supplier-portal T02). session#index resolves the
        // caller's bearer (fail-closed); devLogin is debug-gated; logout ends the
        // client session. Registered before the /portal/{path} SPA catch-all.
        ['name' => 'session#index', 'url' => '/portal/api/session', 'verb' => 'GET'],
        ['name' => 'session#devLogin', 'url' => '/portal/api/session/dev-login', 'verb' => 'POST'],
        ['name' => 'session#logout', 'url' => '/portal/api/session', 'verb' => 'DELETE'],

        // Aggregated portal contributions for the authenticated subject
        // (supplier-portal T04). Guarded by PortalAuthMiddleware (fail-closed).
        ['name' => 'contribution#index', 'url' => '/portal/api/contributions', 'verb' => 'GET'],
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
