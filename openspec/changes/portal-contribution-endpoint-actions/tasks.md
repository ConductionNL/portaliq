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
- [ ] 4.4 Run Hydra gates (route-reachability, spec-coverage,
      forbidden-patterns) before push. — not run as part of this apply pass
      (process/review step); flag for the PR review stage.

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
