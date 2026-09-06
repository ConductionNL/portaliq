# Design: contribution-landing-page-action

## Context

Phase 0 of the pipelinq marketing programme needs a way for pipelinq to ask
Portaliq to create a landing page with a form, and for Portaliq to hand
visitor submissions back. Research into the existing contract (see the PR
description / commit history of this change for the full research trail)
established three things that shaped every decision below:

1. **ADR-046's portal-contribution contract has no inbound "create content"
   verb.** Its `endpoint`-type actions forward a **portal visitor's** action
   OUT to a domain app (`ContributionController::action()`,
   `POST /portal/api/actions/{appId}/{actionId}`) — the opposite direction
   from "a domain app asks Portaliq to create a page."
2. **ADR-041 (cross-app commands via events) is the fleet's canonical, and
   only, mechanism for a same-instance app-to-app call.** It is used
   fleet-wide (decidesk/decidiq, docudesk, softwarecatalog, procest) and
   gate-27 (`no-phantom-cross-app-rpc`) actively bans the alternative (a
   server-side HTTP call to a sibling app's own route).
3. **ADR-108 assigns citizen/anonymous-facing surfaces to Portaliq, not to
   the app that wants them.** pipelinq must not stand up its own public
   form-submission controller; Portaliq already has one
   (`ContributionController::createAnonymous()`, portal-page-provisioning)
   and it is the correct home for a landing-page form submission too.

These three together rule out a new HTTP route for either direction, and
rule out modelling this as a portal-contribution-contract action.

## Goals / Non-Goals

**Goals**: a validated, draft-only page+form creation path; a visitor
submission path that reuses the EXISTING anonymous contribution-create
machinery (whitelisting, `#[AnonRateLimit]`, fail-closed defaults) with zero
new HTTP surface; UTM first/last-touch capture that is honest about being
client-observed, advisory data; a fail-safe (not fail-closed) notification
back to the contributing app.

**Non-Goals**: pipelinq's own consumer-side code (phase 4); a general fix for
portaliq-cms's known route-uniqueness gap outside this action; a canonical
"primary domain" concept; any cross-instance/webhook delivery.

## Decisions

### Decision 1: ADR-041 typed events, not an endpoint action, not a new route

**Alternatives considered:**
- *Extend the `endpoint`-action forward pattern* — rejected: that pattern's
  entire authorisation model is "does this action appear in the **visitor
  subject's** aggregated manifest" (`authorisedEndpointAction()`). There is no
  visitor here; the caller is a same-instance app. Bending that model to fit
  would mean inventing a fake "subject" for an app, which is exactly the kind
  of semantic mismatch `hydra-gate-semantic-auth` exists to catch.
- *A new internal HTTP route, admin-token-guarded* — rejected: same-instance
  HTTP-to-a-sibling-app's-own-route is the pattern gate-27 exists to ban
  (`IntegrationRegistry::getLeaf`, `$registry->call()`, and equivalents were
  removed fleet-wide for exactly this reason — no session on a server-to-
  server call to a `#[NoAdminRequired]` route means a 401, and anything
  public enough to answer without a session is a route ADR-108 says belongs
  to Portaliq, not to the caller in the first place).
- **ADR-041 typed event with a result slot — chosen.** Same-instance,
  synchronous, no network hop, no new route, and it is the pattern every
  other cross-app "ask another app to do something and get an id back" case
  in the fleet already uses (`DecisionRequestedEvent`,
  `DocumentSigningRequestedEvent`).

### Decision 2: two events, not one — request/response is not the same shape as producer/consumer

Direction 1 (create page) has pipelinq as producer, Portaliq as target
(Portaliq's listener does the work and writes the result slot). Direction 2
(submission handoff) is the MIRROR — Portaliq is now the one asking to be
heard, pipelinq is the target. Per ADR-041, the event class always lives in
the TARGET's namespace. So `LandingPageRequestedEvent` is
`OCA\Portaliq\Event\...` (Portaliq is target) and
`LandingPageFormSubmittedEvent` is `OCA\{consumer}\Event\...` (the consumer is
target) — Portaliq ships ONLY the dispatch-and-resolve-by-class_exists() side
of the second event, and documents the exact constructor shape a consumer
must implement (contract.md), because the class itself cannot live in
Portaliq's namespace without inverting the ADR-041 convention.

### Decision 3: the submission write reuses `portal-page-provisioning`'s anonymous mechanism, not a new one

`ContributionController::createAnonymous()` already does everything a
landing-page form submission needs: subject-less write, whitelist, server-
stamped `defaults`, `#[AnonRateLimit(limit: 60, period: 60)]`. The only gap
is that its authorisation source (`aggregateAnonymous()`) reads STATIC
provider declarations — there is nothing statically declared for a
dynamically-created form. Portaliq's own built-in `PortalContributionProvider`
already solves exactly this shape for `portalPage` objects (reads
`status: active` rows from OpenRegister at request time and folds them into
its returned manifest). This change extends that SAME class to also fold in
active `form` objects — one synthesised `anonymous: true`, `type: create`
action per form, `register: portaliq`, `schema: landingPageSubmission`,
`fields` = the form's own field ids plus the three fixed tracking fields
(`utmFirstTouch`, `utmLastTouch`, `referrer`), `defaults` stamping
`formId`/`pageId`/`pageRoute`/`portal`/`sourceApp`/`externalReference` so the
client can never choose or omit them.

**Why not build a dedicated `PortalFormContributionProvider` class instead of
extending the existing one?** `PortalContributionRegistry` discovers exactly
ONE provider per app by convention FQCN
(`OCA\{Namespace}\Portal\PortalContributionProvider`, see
`Application.php`'s comment) — a second provider class in the same app is
invisible to discovery. Extending the existing class is the only way to add a
second source of anonymous entries without changing the discovery mechanism
itself (out of scope, cross-cutting for every app).

### Decision 4: `submittedAt`/`nonce` are server-stamped; UTM/referrer are client-observed and accepted as-is

ADR-005 says identity/computed fields are never trusted from the client. UTM
parameters and `document.referrer` are neither — they are marketing
attribution data Portaliq has no other way to know (it does not track an
anonymous visitor across page loads by itself), and a client lying about its
own UTM values has no security consequence (worst case: a campaign report is
wrong, not an authorization bypass). `submittedAt` and `nonce`, by contrast,
ARE stamped server-side, because they are the WMEBV-adjacent proof-of-receipt
fields other `portalSubmission`-style rows already treat as server-owned
timing/replay-resistance data.

### Decision 5: `landingPageSubmission` is a new schema, not a reuse of `portalSubmission`

`portalSubmission` (existing, `SubmissionReceiptService`) requires
`subjectRef` and `organisation` — a resolved, authenticated portal account. A
landing-page visitor has neither. Reusing it would mean either making those
fields optional (weakening an existing WMEBV compliance schema for every
OTHER consumer of it) or stamping a fake subject (worse — it would make an
anonymous submission look like it came from a real portal account in every
future read of that log). A dedicated schema keeps both concerns honest.

### Decision 6: the dispatch listener is `ObjectCreatedEvent`-driven, not a `ContributionController` constructor addition

`ContributionController` already injects 13 services;
`CmsCacheInvalidationListener` establishes the precedent of reacting to
`OCA\OpenRegister\Event\ObjectCreatedEvent` for a `landingPageSubmission` (or
`page`/`form`) write instead of hand-wiring a new dependency into the
already-large controller. This keeps the controller's existing, carefully
audited authorisation logic completely untouched by this change.

### Decision 7: route uniqueness and `publicUrl` are closed narrowly, not fleet-wide

`portaliq-cms`'s own spec already documents "a route is not checked for
uniqueness within its portal" as a known, Not Implemented gap, and
`portal.domains[]` has no canonical "primary domain" flag. Both are genuine
platform gaps. This change closes route uniqueness ONLY for objects it
creates (a query scoped to `portal` + `route` inside
`LandingPageProvisioningService`, before any write) and derives `publicUrl`
from the FIRST verified domain (or `null`) — it does not retrofit
`CmsReader`/the general page-write path, which has no write path to retrofit
today (per `portal-page-designer`, page writes go straight to OpenRegister
from the SPA, not through a service this change could safely intercept
without touching unrelated designer code).

## Risks / Trade-offs

- [Risk] A `form`/`page` pair could be created but the `page` write fails
  after the `form` write succeeds (no multi-object transaction in this
  codebase's established OpenRegister-write patterns) → [Mitigation] the
  orphan `form` object is inert (nothing serves it without a `page`
  referencing its id in a widget), logged as a warning; a future repair step
  could sweep orphans, out of scope here.
- [Risk] The consumer half of Direction 2 does not exist until pipelinq's
  phase 4 ships → [Mitigation] fail-safe skip-and-log (Decision 2 / proposal
  Risk 1); no visitor-facing failure, no data loss (the write to
  `landingPageSubmission` already happened).
- [Risk] `publicUrl` can be `null` for a portal with no verified domain →
  [Mitigation] documented in contract.md; callers must handle the null case.

## Migration Plan

Additive schema changes only (`lib/Settings/portaliq_register.json`): two new
schemas, one new optional property on `page`. No data migration, no
destructive change. Deployed via the existing OpenRegister register-import
repair step (`appinfo/info.xml`), unchanged by this PR. See migration.md
(skipped — see rationale there).

## Open Questions

None remaining at design time; uncertainty encountered during research was
resolved into the Decisions above rather than left open, and is recapped as
DEFERRED_QUESTIONS in the implementation report for the human reviewer to
confirm or override.
