## 1. Schema

- [x] 1.1 Add the `portalPage` schema to `lib/Settings/portaliq_register.json`
      (`components.schemas.portalPage`) per `design.md`'s shape: `label`,
      `audience`, `minTrust`, `status` at the top level; `collections[]`,
      `actions[]`, `pages[]` as nested arrays. Every property gets `title` +
      `description` (ADR-011). `x-openregister: {publicRead: false,
      publicWrite: false}`.
- [x] 1.2 Add `anonymous` (boolean) to the `collections[]` and `actions[]`
      item schemas.
- [x] 1.3 Bump `portalPage.version` (new schema, start `0.1.0`) and
      `info.version` in `portaliq_register.json` (cache-bust / import-gate).
- [x] 1.4 Add 1-2 seed `portalPage` example objects under
      `components.objects` (mirrors the existing `dev-supplier-account`
      pattern) — one `anonymous: true` create action against a demo schema,
      exercisable on a dev install without a real domain app.

## 2. Registry + interface

- [x] 2.1 `PortalContributionRegistry::aggregateAnonymous(): array` — iterate
      installed providers (same discovery as `aggregateFor()`), keep only
      `collections`/`actions` entries with `anonymous === true`, run through
      the existing `PortalManifestNormaliser`.
- [x] 2.2 Fail-closed mutual exclusion: an entry with `anonymous: true` AND a
      non-`low` `minTrust` has `anonymous` dropped by the normaliser (falls
      back to requiring authentication) — add this rule to
      `PortalManifestNormaliser`.
- [x] 2.3 Update `IPortalContributionProvider`'s docblock to document the new
      optional `anonymous` field on collections/actions (duck-typed,
      additive — no interface method signature change).

## 3. Built-in provider

- [x] 3.1 `lib/Portal/PortalContributionProvider.php`: replace the hardcoded
      `getContribution()` body with a `PortalObjectReader`-backed read of
      ACTIVE `portalPage` objects matching `$subject['audience']`; convert
      1:1 into the manifest shape. First-match-not-merge on multiple active
      objects for the same audience (log a warning on collision).
- [x] 3.2 `getAudiences()`: return the distinct `audience` set from active
      `portalPage` objects instead of the hardcoded `['supplier']`.
- [x] 3.3 Provider participates in `aggregateAnonymous()`: reads active
      `portalPage` objects regardless of audience, surfaces only their
      `anonymous: true` entries.

## 4. Anonymous write path

- [x] 4.1 `PortalObjectWriter::createAnonymousObject(register, schema,
      data): ?array` — calls `saveObject(..., _rbac: false, _multitenancy:
      false)` with NO scope/subject/organisation stamping.
- [x] 4.2 `ContributionController::authorisedAnonymousCreateAction(register,
      schema): ?array` — mirrors `authorisedCreateAction()` but reads
      `aggregateAnonymous()` and requires `anonymous === true` on the
      matched action.
- [x] 4.3 `ContributionController::create()`: when `subject()` returns null,
      try the anonymous path before returning 401; on a match, whitelist +
      apply `defaults` exactly as today, write via
      `createAnonymousObject()`.
- [x] 4.4 `ContributionController::index()`: when `subject()` returns null,
      return `aggregateAnonymous()`'s manifest instead of 401.

## 5. Middleware

- [x] 5.1 `PortalAuthMiddleware::beforeController()`: before throwing
      `PortalUnauthorizedException`, check `aggregateAnonymous()` for a
      matching entry (per `design.md`'s per-method rule for `create`/
      `index`); let the request through on a match, throw unchanged
      otherwise.

## 6. Tests

- [x] 6.1 `PortalManifestNormaliserTest`: anonymous+non-low-minTrust on one
      entry → `anonymous` dropped, entry still present but trust-gated.
- [x] 6.2 `PortalContributionRegistryTest`: `aggregateAnonymous()` surfaces
      only `anonymous: true` entries and drops private siblings in the same
      contribution.
- [x] 6.3 `ContributionControllerTest`: anonymous create with no bearer
      succeeds for an `anonymous: true` action and still 401s for every
      non-anonymous action (regression); whitelist/`defaults` still enforced
      on the anonymous path.
- [x] 6.4 `PortalAuthMiddlewareTest`: no-bearer request to a non-anonymous
      route still throws; no-bearer request to an anonymous-declared
      `(register, schema)` passes through.
- [x] 6.5 Built-in provider test: `portalPage` object → manifest conversion,
      multiple-active-for-one-audience picks first + logs, empty register →
      `getAudiences()` returns `[]` not null.

## 7. Gates

- [x] 7.1 Run Hydra gates (spec-coverage, route-reachability,
      semantic-auth, no-admin-idor, notification-dialect) before push —
      the anonymous branch in particular needs `semantic-auth` /
      `no-admin-idor` scrutiny since it deliberately relaxes a fail-closed
      default.
