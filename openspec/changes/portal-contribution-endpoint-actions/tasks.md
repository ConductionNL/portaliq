## 1. Contract

- [x] 1.1 Documented the ACTUAL shipped `endpoint`-type action shape in
      `IPortalContributionProvider`'s docblock: `{id, label, endpoint,
      method?, minTrust?}` (an instance-local absolute path directly, not a
      separate `appId`/`path` pair — the forwarding route's `{appId}` segment
      is the CONTRIBUTING app, matched against the aggregate's own `app` key,
      not a field on the action itself) — plus the new optional `fields`
      whitelist (task 2.2 below). NOTE: on inspecting the codebase this
      contract, the route, and the controller's `action()` method were
      ALREADY IMPLEMENTED (contract-v2 change, T8) before this change was
      applied — this task updates the docblock to match reality and closes
      the remaining gaps (2.2, 3.1, 3.2) rather than re-building an existing
      path.
- [x] 1.2 `appinfo/routes.php` route `POST
      /portal/api/actions/{appId}/{actionId}` already existed
      (`contribution#action`), guarded by `#[PublicPage]` +
      `PortalAuthMiddleware` (via `PortalProtected`) exactly as specified.

## 2. Authorised invoke path

- [x] 2.1 `ContributionController::action(string $appId, string $actionId)`
      (the shipped method name — no separate `invoke()` was added) already
      resolves the subject, finds the matching action via
      `authorisedEndpointAction()` (sibling to `authorisedCreateAction()`),
      and 403s when not entitled — verified with existing + new tests.
- [x] 2.2 Added: when the matched action declares a `fields` whitelist, the
      forwarded body is rebuilt server-side from ONLY those fields (reusing
      `whitelist()`, shared with `create()`) — closes the actual gap (the
      shipped code previously always relayed the raw request body). Absent
      `fields` still relays the raw body unchanged (backward compatible).
- [x] 2.3 The signed `X-Portal-Subject` assertion (`PortalJwtService` /
      `PortalSessionService::issueAssertion()`) already existed, carrying the
      subject's server-derived `subjectRef` + `organisation` and a distinct
      `use: "assertion"` claim (not a separate `internal-forward` audience —
      the existing token-confusion guard already prevents an assertion from
      being replayed as a session, which is the security property this task
      needed).
- [x] 2.4 The forward + non-2xx/unreachable → 502 mapping already existed
      (`action()`'s try/catch → `{error: 'forward_failed'}`, `STATUS_BAD_GATEWAY`).

## 3. Frontend

- [x] 3.1 `src/portal/App.jsx`: endpoint-type actions now render as enabled
      buttons (previously `disabled={!a.endpoint}` with NO click handler at
      all) wired to `invokeEndpointAction(appId, action)`, which collects any
      declared whitelisted `fields` via the same prompt-based pattern
      `createInCollection()` uses, then `POST`s to
      `/portal/api/actions/{appId}/{actionId}`.
- [x] 3.2 Added per-action `actionFeedback` state (pending / ✓ / ✕) rendered
      next to the button — the forward's success/failure is now surfaced
      instead of silently swallowed.

## 4. Demo + tests

- [x] 4.1 A demo `endpoint` action already existed in Portaliq's own
      `PortalContributionProvider` (`exampleForward`, plus `exampleTrusted`
      gated above `low` trust) — refactored `getContribution()` into
      `exampleCollections()` / `exampleActions()` helpers (also fixed a
      pre-existing phpmd `ExcessiveMethodLength` violation on this file, hit
      while touching it).
- [x] 4.2 PHPUnit: `ContributionControllerTest` already had 401
      (`testUnauthenticatedActionIs401`), 403-for-unknown-app/action
      (`testActionOutsideManifestIs403WithoutOutboundCall`), SSRF/trust
      guards, and 502-on-transport-failure coverage. Added
      `testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes`
      and `testActionWithoutDeclaredFieldsForwardsTheRawBodyUnchanged` for
      the new whitelist behaviour (task 2.2).
- [x] 4.3 The assertion-vs-session distinction was already unit-tested in
      `PortalSessionServiceTest` (`testAssertionCarriesUseClaimSessionJtiAndShortTtl`,
      `testAssertionPresentedAsBearerFailsClosed`); no new audience claim was
      introduced (see 2.3), so no additional test was needed for that specific
      shape.
- [x] 4.4 Run Hydra gates before push. **Done — and seven gates failed, every one of them on code written in this session.**
      **gate-7 (no-admin-idor)** was the serious one: `TrafficController::summary` shipped `#[NoAdminRequired]` while taking a portal SLUG,
      so any authenticated user could read any tenant's traffic by naming it. `PageRegionsController::update` had the same shape and was not
      flagged — it takes a slug and a route and REWRITES that page — so both are now admin-only, carrying `@auth admin-only` and no auth
      attribute, which is Nextcloud's instance-admin default and the posture `SessionAdminController` already documents. Scoping to the
      caller's organisation would be better and this app has no mechanism for it: `AdminSettings` is deliberately a plain `ISettings`, so
      there is no delegated portal-operator role to scope BY, and inventing one would assert a boundary that does not exist.
      **gate-82** — the preflight and the client script were public with no ceiling. **gate-38** — the editor document had no skip link.
      **gate-32** — the canvas's empty-region invitation was a bare `@click` on a `div`, and is now a real control with a role, a tab stop
      and key handlers; the canvas root's click is a POINTER SHORTCUT wired in `mounted()` because every block is already selectable from
      the layer tree's real buttons, so the keyboard path exists and making a page preview a `role=button` tab stop would be worse.
      **gate-60** — the `ChartBar` icon on the new aggregate schema was never registered, so it rendered as nothing. **gate-51** — 23 schema
      properties had no description. **gate-25** — four new public endpoints had no contract test.
      All seven now pass: **60 of 60 applicable gates**, 624 PHPUnit tests, 12/12 surfaces, shell unchanged, editor parity holding.

## Notes on scope taken

This change's proposal was written against an earlier state of the codebase;
by the time it was applied, the backend contract (route, controller method,
SSRF/trust guards, demo action) had ALREADY shipped as part of the
`contract-v2` change (T8). The real, remaining gaps were: (1) the frontend
never actually wired a click handler for endpoint-type actions (silently
inert `disabled={!a.endpoint}` buttons with no `onClick`), (2) no
server-side field whitelist on the forward, (3) no user-visible
success/failure feedback. All three are now closed. No `appId`/`path`
split or `invoke()` rename was introduced — the shipped `action()` method and
`{id, label, endpoint, method?, minTrust?}` shape were kept as the source of
truth.
