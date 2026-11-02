# Tasks: portal-contribution-endpoint-actions

As built (checked box by box against the code on 15 Oct 2026). The frontend
half was first built in the React portal (`src/portal/App.jsx`), which has
since been removed; the same behaviour lives in the Vue site, and each box
names where it is now. The demo actions of task 4.1 are gone: the provider
was rewritten in 23cdf198 (portal-page-provisioning).

## 1. Contract

- [x] 1.1 The endpoint action shape is documented in `lib/Contribution/IPortalContributionProvider.php`: `{id, label, endpoint, method?, minTrust?}` plus the optional `fields` whitelist. `endpoint` is an instance-local absolute path; the forwarding route's `{appId}` is the contributing app (the contribution's own `app` key), not a field on the action. No `appId`/`path` pair was introduced.
- [x] 1.2 `appinfo/routes.php`: `POST /portal/api/actions/{appId}/{actionId}` (`contribution#action`), `#[PublicPage]` behind `PortalAuthMiddleware`.

## 2. Authorised invoke path

- [x] 2.1 `ContributionController::action()` resolves the subject (401 without one), finds the action through `authorisedEndpointAction()` and `isForwardableAction()` (instance-local path, allowed method, `minTrust`), and answers 403 when the subject is not entitled.
- [x] 2.2 A declared `fields` whitelist rebuilds the forwarded body server-side through `whitelist()` (shared with `create()`); without `fields` the raw body is relayed.
- [x] 2.3 The signed `X-Portal-Subject` assertion (`PortalSessionService::issueAssertion()`) carries the server-derived `subjectRef` and `organisation` and a `use: "assertion"` claim, so an assertion cannot be replayed as a session.
- [x] 2.4 A transport failure answers 502 `{error: "forward_failed"}`; a reachable app's answer is relayed with its own status.

## 3. Frontend

- [x] 3.1 The Vue site runs an endpoint action through `src/site/lib/residentActions.js` (`postAction` posts to `/portal/api/actions/{appId}/{actionId}`; `forwardApi` gives `forwardAction`), called from `ActionButton.vue` via `runAction()` in `src/shared/rowAction.js`. An action that declares fields opens its form first (`ActionBlock.vue`, site-action-forms).
- [x] 3.2 `ActionButton.vue` shows "Please wait…" while busy, then the leaf app's answer in a `role="status"` region, or follows its redirect (site-reaches-portal-parity REQ-SRP-027).

## 4. Demo + tests

- [x] 4.1 The demo actions (`exampleForward`, `exampleTrusted`, `exampleCollections()`, `exampleActions()`) no longer exist: `PortalContributionProvider` was rewritten in 23cdf198 and declares no demo endpoint action. The behaviour is covered by the tests in 4.2 and 4.3 instead.
- [x] 4.2 `tests/Unit/Controller/ContributionControllerTest.php`: `testUnauthenticatedActionIs401`, `testActionOutsideManifestIs403WithoutOutboundCall`, `testActionSsrfAndTrustGuardsFailClosed`, `testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes`, `testActionWithoutDeclaredFieldsForwardsTheRawBodyUnchanged`, `testActionForwardsWithAssertionAndRelaysResponse`, `testActionTransportFailureIs502ForwardFailed`. Site side: `tests/row-action.spec.mjs` ("a page-level action shows the leaf app answer instead of discarding it (#804)", "an endpoint or cta action on a site page shows the leaf answer in a status region").
- [x] 4.3 `tests/Unit/Service/PortalSessionServiceTest.php`: `testAssertionCarriesUseClaimSessionJtiAndShortTtl`, `testAssertionPresentedAsBearerFailsClosed`.
- [x] 4.4 Hydra gates: run on the archive PR (all applicable gates pass).
