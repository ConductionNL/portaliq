<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        // First-time setup wizard (ADR-042) - the standard CnSetupWizard contract.
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',           'verb' => 'POST'],
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

        // Store plane (ADR-080, ADR-114 Decision 4): the engine's two store
        // routes, verbatim from OpenRegister's AppHost\Routes::standard()
        // table, which Portaliq does not call (see settings#update above).
        // The names resolve to Controller\StoreController, a class this app
        // does NOT ship — AppInfo\StorePlaneRegistrar aliases that name at
        // OpenRegister's GenericStoreController, and the auth posture lives
        // there (search: signed-in; install: the manifest's installAuth,
        // admin by default). ACCEPTED, NOT CHOSEN: search is reachable by any
        // signed-in user — one tier below the rest of this app's admin-only
        // /api/ — because the catalogue is publisher-side public and the
        // engine has no searchAuth key to narrow it (review of #500; raise it
        // with OpenRegister's apphost-store-plane spec if Portaliq ever wants
        // an admin-only catalogue). Without these entries the manifest's
        // `type: "store"` page called /api/store/items and the SPA catch-all
        // below answered it with HTML 200 ("The store registry did not
        // answer", WOO-559).
        ['name' => 'store#search', 'url' => '/api/store/items', 'verb' => 'GET'],
        [
            'name' => 'store#install',
            'url' => '/api/store/items/{slug}/install',
            'verb' => 'POST',
            'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]'],
        ],
        // Invitations into the portal (portal-identity-and-the-organisations-cases).
        // Staff acts behind the same `portal.provision` action.
        ['name' => 'portalAccountAdmin#invite', 'url' => '/api/invitations', 'verb' => 'POST'],
        ['name' => 'portalAccountAdmin#invitations', 'url' => '/api/invitations', 'verb' => 'GET'],

        // The identity space (portal-identity-space). Staff acts, gated by
        // the ADR-023 action `portal.provision`; a citizen never reaches them.
        ['name' => 'portalAccountAdmin#provision', 'url' => '/api/accounts/provision', 'verb' => 'POST'],
        ['name' => 'portalAccountAdmin#void', 'url' => '/api/accounts/void', 'verb' => 'POST'],

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

        // Events and sign-ups (events-and-signups, findings 9.6, 9.8). Staff
        // authoring requires a Nextcloud session; the guardian-facing
        // feed/rsvp/signup routes are PortalProtected (bearer session).
        ['name' => 'event#create', 'url' => '/api/events', 'verb' => 'POST'],
        ['name' => 'event#publish', 'url' => '/api/events/{id}/publish', 'verb' => 'PUT'],
        ['name' => 'eventGuardian#feed', 'url' => '/api/events/feed', 'verb' => 'GET'],
        ['name' => 'eventGuardian#rsvp', 'url' => '/api/events/{id}/rsvp', 'verb' => 'POST'],
        ['name' => 'eventGuardian#signup', 'url' => '/api/events/{id}/signup', 'verb' => 'POST'],

        // Traffic analytics (portal-traffic-analytics). Public like the
        // content API above, for the same reason: a visitor's browser on a
        // portal's own domain, or on a statically built site elsewhere, has
        // no Nextcloud session. The collector resolves the portal by HOST
        // first and accepts a slug only when the host resolves nothing.
        // Registered here, ahead of the SPA catch-all.
        ['name' => 'traffic#collect', 'url' => '/api/traffic', 'verb' => 'POST'],
        ['name' => 'traffic#pixel', 'url' => '/api/traffic/pixel.gif', 'verb' => 'GET'],
        ['name' => 'traffic#client', 'url' => '/api/traffic-client.js', 'verb' => 'GET'],
        // portal-traffic-reporting: a trusted backend reports on a
        // visitor's behalf with the portal's bearer token (public route,
        // token-guarded), and an admin downloads the daily records.
        ['name' => 'traffic#server', 'url' => '/api/traffic/server', 'verb' => 'POST'],
        ['name' => 'trafficReport#export', 'url' => '/api/traffic/export', 'verb' => 'GET'],
        // portal-traffic-experiments: a session recording chunk, and the
        // recorder the client loads only for a portal that switched
        // recording on. Both public for the collector's reasons.
        ['name' => 'traffic#recording', 'url' => '/api/traffic/recording', 'verb' => 'POST'],
        ['name' => 'traffic#recorder', 'url' => '/api/traffic-recorder.js', 'verb' => 'GET'],

        // The editing-context probe (portal-page-designer). NOT part of the
        // headless content contract: it answers a question about the CALLER
        // (may this session edit this route's page), which a third-party
        // front-end has no equivalent of. Registered here so it sits ahead of
        // the SPA catch-all like the content routes above.
        ['name' => 'cmsEditor#editingContext', 'url' => '/api/cms/editing-context', 'verb' => 'GET'],

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
        // Sign in with a Nextcloud account — the `nextcloud` authentication
        // mode. The SPA has always rendered a button pointing here; until now
        // no route answered it, so the button 404'd. Not public: the caller's
        // Nextcloud session IS the credential, and an anonymous visitor is
        // handed to Nextcloud's own login form.
        ['name' => 'session#nextcloud', 'url' => '/portal/api/session/nextcloud', 'verb' => 'GET'],
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
        // The embedded intake form (embedded-intake-form). The frame is served
        // from the portal's own origin with `frame-ancestors` built from that
        // form's own list, and its submit route is the ordinary anonymous
        // intake path with the origin recorded on the submission.
        ['name' => 'portalEmbed#frame', 'url' => '/portal/embed', 'verb' => 'GET'],
        ['name' => 'portalEmbed#submit', 'url' => '/portal/api/embed/submit', 'verb' => 'POST'],

        // The intake form as an object (portal-intake-form-as-an-object): the
        // entry point over opencatalogi's published catalogue, the form a
        // binding resolves to at render time, the submission, and the
        // reference page that reads the submission's real state.
        ['name' => 'portalIntake#catalogue', 'url' => '/portal/api/intake/catalogue', 'verb' => 'GET'],
        ['name' => 'portalIntake#form', 'url' => '/portal/api/intake/form', 'verb' => 'GET'],
        ['name' => 'portalIntake#submit', 'url' => '/portal/api/intake/submit', 'verb' => 'POST'],
        ['name' => 'portalIntake#status', 'url' => '/portal/api/intake/status', 'verb' => 'GET'],

        // The citizen's own identity (portal-identity-and-the-organisations-cases):
        // the challenge this portal runs itself, the one-time reference link
        // for a case type that admits it, registration under the portal's
        // policy, and the account's own details.
        ['name' => 'portalIdentity#challenge', 'url' => '/portal/api/identity/challenge', 'verb' => 'GET'],
        ['name' => 'portalIdentity#requestReferenceLink', 'url' => '/portal/api/identity/reference-link', 'verb' => 'POST'],
        ['name' => 'portalIdentity#redeemReferenceLink', 'url' => '/portal/api/identity/reference-link/redeem', 'verb' => 'POST'],
        ['name' => 'portalIdentity#register', 'url' => '/portal/api/identity/register', 'verb' => 'POST'],
        ['name' => 'portalIdentity#acceptInvitation', 'url' => '/portal/api/identity/invitation/accept', 'verb' => 'POST'],
        ['name' => 'portalAccountSelf#updateDetails', 'url' => '/portal/api/identity/details', 'verb' => 'PATCH'],
        ['name' => 'portalAccountSelf#confirmEmail', 'url' => '/portal/api/identity/email/confirm', 'verb' => 'POST'],
        ['name' => 'portalAccountSelf#removeAccount', 'url' => '/portal/api/identity/remove', 'verb' => 'POST'],
        ['name' => 'portalAccountSelf#requestAccess', 'url' => '/portal/api/identity/access-requests', 'verb' => 'POST'],
        ['name' => 'portalAccountSelf#myAccessRequests', 'url' => '/portal/api/identity/access-requests', 'verb' => 'GET'],

        // Mijn zaken (portal-identity-space): every `kind: cases` collection
        // the subject's contributions declare, merged into one list, so a case
        // attached to the account before the first login is there on it.
        ['name' => 'myCases#index', 'url' => '/portal/api/my-cases', 'verb' => 'GET'],
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

        // The resident task leg (portal-task-delivery): bearer-guarded proxy
        // over openregister's portal task seam. Portaliq mints the
        // X-Portal-Subject assertion server-side; the browser never calls
        // openregister. Registered before the /portal/{path} SPA catch-all.
        // The change-proposal queue (change-proposal-queue). A proposal never
        // writes the record: only a reviewer accepting one does, with their
        // own rights. The staff routes are gated by `portal.review-proposal`.
        ['name' => 'proposal#index', 'url' => '/api/proposals', 'verb' => 'GET'],
        ['name' => 'proposal#proposeAsColleague', 'url' => '/api/proposals', 'verb' => 'POST'],
        ['name' => 'proposal#accept', 'url' => '/api/proposals/{id}/accept', 'verb' => 'POST'],
        ['name' => 'proposal#acceptConfirmingDrift', 'url' => '/api/proposals/{id}/accept-confirming-drift', 'verb' => 'POST'],
        ['name' => 'proposal#reject', 'url' => '/api/proposals/{id}/reject', 'verb' => 'POST'],
        ['name' => 'proposal#proposeFromPortal', 'url' => '/portal/api/proposals', 'verb' => 'POST'],
        ['name' => 'proposal#withdraw', 'url' => '/portal/api/proposals/{id}/withdraw', 'verb' => 'POST'],

        // A report of wrongdoing filed without an account
        // (a-report-without-an-account-and-a-custodian-who-may-reveal-it).
        // The three portal routes take no session and no address: the receipt
        // code is the whole identity, and a wrong one is throttled. The staff
        // routes never return a contact detail; only the reveal a custodian
        // allowed does, and that is its own recorded act.
        ['name' => 'report#file', 'url' => '/portal/api/reports', 'verb' => 'POST'],
        ['name' => 'report#thread', 'url' => '/portal/api/reports/thread', 'verb' => 'POST'],
        ['name' => 'report#answer', 'url' => '/portal/api/reports/thread/answer', 'verb' => 'POST'],
        ['name' => 'report#show', 'url' => '/api/reports/{id}', 'verb' => 'GET'],
        ['name' => 'report#reply', 'url' => '/api/reports/{id}/messages', 'verb' => 'POST'],
        ['name' => 'report#requestReveal', 'url' => '/api/reports/{id}/reveal-requests', 'verb' => 'POST'],
        ['name' => 'report#decideReveal', 'url' => '/api/reveal-requests/{id}/decide', 'verb' => 'POST'],

        // A handler asks an outside partner for something from the case
        // (partner-tasks-in-the-portal). Staff-facing and gated by the ADR-023
        // action `portal.ask-partner` plus a read of the case with RBAC on.
        ['name' => 'partnerTask#ask', 'url' => '/api/partner-tasks/ask', 'verb' => 'POST'],
        ['name' => 'portalTaskProxy#index', 'url' => '/portal/api/tasks', 'verb' => 'GET'],
        ['name' => 'portalTaskProxy#show', 'url' => '/portal/api/tasks/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
        ['name' => 'portalTaskProxy#complete', 'url' => '/portal/api/tasks/{uuid}/complete', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],

        // What a citizen may write on their own case
        // (what-the-citizen-may-write-on-their-own-case). Three acts, three
        // routes, because an amendment, a document and a task answer are not
        // one write to a citizen or to the law (D2). The task answer is the
        // fourth act and stays on the task proxy above. Registered before the
        // /portal/{path} SPA catch-all.
        ['name' => 'citizenCase#show', 'url' => '/portal/api/citizen/cases/{register}/{schema}/{id}', 'verb' => 'GET'],
        ['name' => 'citizenCase#amend', 'url' => '/portal/api/citizen/cases/{register}/{schema}/{id}', 'verb' => 'PATCH'],
        ['name' => 'citizenCase#addDocument', 'url' => '/portal/api/citizen/cases/{register}/{schema}/{id}/documents', 'verb' => 'POST'],
        // Ending your own request (withdrawing-your-own-case-from-the-portal).
        // Its own act, its own event: a withdrawal is not an amendment that
        // happens to change the status.
        ['name' => 'citizenCase#withdraw', 'url' => '/portal/api/citizen/cases/{register}/{schema}/{id}/withdraw', 'verb' => 'POST'],

        ['name' => 'portalPage#catchAll', 'url' => '/portal/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],

        // Hosted tilburg-woo-ui (Open Tilburg WOO SPA) — public. Registered
        // BEFORE the greedy /{path} catch-all so /woo assets are not swallowed.
        ['name' => 'woo#serve', 'url' => '/woo', 'verb' => 'GET'],
        ['name' => 'woo#servePath', 'url' => '/woo/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        // `(?!api/)` mirrors OpenRegister's canonical table: the SPA never needs an
        // `api/` path, and without the lookahead an UNDECLARED API route is answered
        // with the app shell and HTTP 200 instead of a 404. That is exactly how the
        // store page read "The store registry did not answer" while nothing errored
        // (WOO-559). A missing `/api/…` route now fails loudly.
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '(?!api/).+'], 'defaults' => ['path' => '']],
    ],
];
