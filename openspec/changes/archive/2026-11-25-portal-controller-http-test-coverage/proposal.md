---
kind: code
---

## Why

`openspec/changes/supplier-portal/tasks.md` marks the auth edge (T02), the
contribution registry (T04), collection reads (T05), and create actions (T06)
as `DONE`/`PARTIAL` + **"LIVE-VERIFIED"**, citing a one-time manual probe in
the running container (e.g. T05: "Proven live: dev-supplier sees only its
doc, other-supplier sees only its own"; T06: "Proven live: created a doc as
dev-supplier with a smuggled `subjectRef:HACKER` ... 403 otherwise"). Those
are real, valuable verifications — but they happened once, by hand, and left
**no regression test** behind them. `tests/` confirms the gap directly:

```
$ find tests -iname "*Contribution*" -o -iname "*SessionController*" -o -iname "*PortalPage*"
tests/Unit/Contribution/PortalContributionRegistryTest.php
```

Only the registry has a test file. The three HTTP-facing controllers that
actually carry the security-relevant behaviour described as "DONE" have
**zero** automated coverage:

- `lib/Controller/ContributionController.php` — `index()` (aggregation),
  `collection()` (IDOR guard: register/schema must appear in the subject's own
  contributions, or 403), `create()` (action-authorised, field-whitelisted,
  server-stamped ownership). No test file exists at all.
- `lib/Controller/SessionController.php` — `GET /portal/api/session` resolve,
  `POST /portal/api/session/dev-login` (debug-gated), `DELETE` logout. No test
  file exists (only `PortalSessionService`, the class it delegates to, is
  unit-tested — the controller's own routing/response-shaping/auth-attribute
  wiring is unverified).
- `lib/Controller/PortalPageController.php` — the `#[PublicPage]` shell
  renderer and its CSP `frame-ancestors: *`. No test file exists.

Existing unit tests (`PortalObjectReaderTest`, `PortalObjectWriterTest`,
`ActionAuthServiceTest`, `PortalJwtServiceTest`) test the **service layer**
directly — real and valuable, but they bypass the router, the
`#[PublicPage]`/`#[NoCSRFRequired]` attribute wiring, and
`PortalAuthMiddleware`'s actual `beforeController` invocation against a live
controller instance (only `PortalAuthMiddlewareTest` calls the middleware
directly, with a hand-built `stdClass`/marker interface — never the real
`ContributionController`). This is precisely the unit-vs-e2e distinction the
company's testing posture (hydra ADR-008) exists to catch: a service method
passing its own unit test proves nothing about whether the route is wired,
reachable, and actually gated the way the controller attributes claim.

Concretely, none of these regression-relevant behaviours has an automated
check today:
- A request to `/portal/api/collections/{register}/{schema}` for a
  (register, schema) NOT in the subject's own contributions returns 403 (the
  IDOR guard `authorisedCollection()`,
  `lib/Controller/ContributionController.php:150-163`).
- A `create()` POST with a smuggled `subjectRef` field is stamped server-side,
  not client-controlled (`ContributionController::create()`, T06's live
  probe — never turned into a test).
- `SessionController`'s dev-login endpoint actually 404s/is inert when the
  debug flag is off (only described in a code comment,
  `src/portal/App.jsx:111-112`, never asserted).
- `PortalPageController::index()` sets the CSP header it claims to
  (`addAllowedFrameAncestorDomain('*')`) — no test asserts the response headers
  at all.

## What Changes

- Add PHPUnit tests for `ContributionController`, `SessionController`, and
  `PortalPageController` that instantiate the real controller classes (with
  mocked/stub services, per the existing test style in `tests/Unit/`) and
  assert on the actual HTTP response (status code, JSON body shape, headers)
  — not just the underlying service call.
- Add one Playwright e2e spec exercising the full path through the real
  router: dev-login → `GET /portal/api/contributions` → `GET
  /portal/api/collections/{register}/{schema}` (both the entitled and a
  forbidden collection) → `POST .../collections/{register}/{schema}` create
  action — matching T13's already-described-but-unchecked scope
  (`openspec/changes/supplier-portal/tasks.md` T13 is `[ ]`, unstarted; this
  change delivers it).
- No production code changes — pure test coverage. (If a test surfaces an
  actual behavioural bug, e.g. the CSP wildcard, file that under the relevant
  existing change — `portal-white-label-runtime-config` already tracks the
  CSP fix — rather than fixing it here.)

## Capabilities

### Modified Capabilities
- `supplier-portal`: T13 ("Tests — PHP unit for the auth edge + registry...
  Playwright e2e for the supplier login flow") moves from unstarted to
  delivered for the auth-edge and contribution-read/create paths.

## Impact

- New: `tests/Unit/Controller/ContributionControllerTest.php`
- New: `tests/Unit/Controller/SessionControllerTest.php`
- New: `tests/Unit/Controller/PortalPageControllerTest.php`
- New: `tests/e2e/portal-supplier-flow.spec.ts`
