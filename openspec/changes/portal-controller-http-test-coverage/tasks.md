## 1. ContributionController

- [x] 1.1 `tests/Unit/Controller/ContributionControllerTest.php`: `index()`
      returns 401 JSON when `subject()` resolves null (mock
      `PortalSessionService::resolveFromBearer` → null), 200 with the
      registry's aggregate otherwise. (`ContributionControllerTest` already
      existed with substantial contract-v2 coverage — added the `index()`
      cases plus 1.2/1.4 below, which were the actual gaps.)
- [x] 1.2 `collection()` — 403 when `(register, schema)` is not in the
      subject's own aggregated contributions (the IDOR guard,
      `authorisedCollection()` — distinct from the pre-existing minTrust-based
      403 case); 200 with the reader's rows when it is (already covered).
- [x] 1.3 `create()` — 403 when no matching `type: create` action is declared
      for `(register, schema)` (distinct from the pre-existing minTrust-based
      403 case); 200 + writer call with a smuggled client `subjectRef` field
      ignored was already covered (`testCreateNeverPassesClaimsToTheWriter`).
- [x] 1.4 All three methods — 401 path when the bearer resolves to no subject
      (`action()`'s 401 path already existed; added `index()`, `collection()`,
      `create()`).

## 2. SessionController

- [x] 2.1 `tests/Unit/Controller/SessionControllerTest.php` (new): `GET
      /portal/api/session` — resolves and returns the session shape for a
      valid bearer; returns `authenticated: false` with 401 for an invalid one
      (the code's actual current behaviour).
- [x] 2.2 `POST /portal/api/session/dev-login` — returns a token when the
      debug flag OR the explicit app flag is enabled; returns 404 when both
      are disabled; returns 503 `not_configured` when no dedicated
      `jwt_signing_secret` exists yet (portal-auth-edge-session-hardening).
      All three branches asserted explicitly.
- [x] 2.3 `DELETE /portal/api/session` — logout revokes the caller's own
      session (asserts `PortalSessionService::revoke()` is called with the
      bearer's own `jti`) and always returns `{ok: true}`, including when the
      bearer was already invalid (not itself an error).

## 3. PortalPageController

- [x] 3.1 `tests/Unit/Controller/PortalPageControllerTest.php` (new):
      `index()` returns a `TemplateResponse` rendering the `portal` template
      with `RENDER_AS_PUBLIC`.
- [x] 3.2 Asserts the response's `Content-Security-Policy` `frame-ancestors`
      is `'none'` for an unresolved org and the tenant's configured origins
      (never `*`, never a residual `'self'`) for a resolved one — the fix
      from `portal-white-label-runtime-config` landed in the SAME apply pass,
      so this test exercises the fixed behaviour directly rather than the
      original wildcard.
- [x] 3.3 `catchAll(string $path)` delegates to `index()` for two distinct
      `$path` values (proves deep links resolve to the same shell).

## 4. Playwright e2e (T13)

- [ ] 4.1 `tests/e2e/portal-supplier-flow.spec.ts`: dev-login → assert `GET
      /portal/api/session` reflects authenticated → assert `GET
      /portal/api/contributions` lists the demo contribution. — DEFERRED:
      needs a running Nextcloud + OpenRegister instance with the dev-login
      gate open; not run as part of this apply pass (isolated worktree, no
      live instance available). Left as an explicit gap, not faked.
- [ ] 4.2 Load an entitled collection → assert 200 + rendered rows in the DOM.
      — DEFERRED, same reason as 4.1.
- [ ] 4.3 Attempt a non-entitled `(register, schema)` combination directly
      against the API → assert 403. — DEFERRED, same reason as 4.1.
- [ ] 4.4 Perform a `type: create` action → assert the new row appears after
      the collection reload. — DEFERRED, same reason as 4.1.

## 5. Gates

- [x] 5.1 `composer check:strict`'s fast, high-signal subset — lint + phpcs +
      phpstan + phpunit — all green on every file touched across all six
      changes applied in this pass (118/118 PHPUnit tests). `phpmd`/`psalm`
      not run standalone in this pass (time-boxed).
- [ ] 5.2 Run Hydra gates (spec-coverage, e2e-coverage, spdx-headers) before
      push. — not run as part of this apply pass (process/review step); flag
      for the PR review stage. Note: e2e-coverage will legitimately flag the
      deferred Playwright spec above.
