---
kind: code
---

## Why

Today a portal page can only exist because an app ships a PHP provider class
at the FQCN `OCA\{App}\Portal\PortalContributionProvider`
(`PortalContributionRegistry::PROVIDER_CLASS`,
`lib/Contribution/PortalContributionRegistry.php:63`, enumerated per
installed app at `:98-136`). `IPortalContributionProvider::getContribution()`
(`lib/Contribution/IPortalContributionProvider.php:76-106`) documents the
returned manifest as a declarative array — but the only way to *produce* that
array is to write and ship a PHP class. There is no way for an app (or an
admin) to provision a portal page as **data**. This blocks OpenBuild — and
any future app — from creating a citizen-facing page without shipping PHP.

Separately, every write path Portaliq has today (`ContributionController::
create()`, `lib/Controller/ContributionController.php:482-529`) assumes a
resolved, bearer-authenticated `subject` — `PortalAuthMiddleware`
(`lib/Middleware/PortalAuthMiddleware.php:78-88`) 401s before any
`PortalProtected` controller method runs at all when no valid bearer is
present, unconditionally, for every method. That is correct for the
subject-owned data every existing collection/action manages, but it makes an
**anonymous** citizen submission (no DigiD/eHerkenning, no bearer, no portal
account) structurally impossible today, even though anonymous intake (a
report-an-issue form, a public sign-up, a contact form) is a first-class,
available-now capability that must not wait on a real identity-provider
integration — DigiD/eHerkenning are optional trust *elevation* for pages that
need it, not a precondition for the portal to accept public input.

## What Changes

1. **`portalPage` schema** in Portaliq's own register
   (`lib/Settings/portaliq_register.json`) — one object = one data-provisioned
   contribution, in the exact shape `IPortalContributionProvider::
   getContribution()` already documents: `label`, `audience`, `minTrust`,
   `collections[]` (each: `register`, `schema`, `scopeField`, `minTrust`,
   `anonymous`, `fields`, `columns`, `detail`), `actions[]` (each: `type`
   create/update, `register`, `schema`, `fields` whitelist, `set`,
   `defaults`, `endpoint`, `minTrust`, **`anonymous`**), `pages[]` (`id`,
   `label`, `icon`, `blocks[]` of `collection`|`action`|`detail`|`richText`|
   `cta`), `status` (`active`|`draft`). The vocabulary is mirrored verbatim
   from `IPortalContributionProvider` and `PortalManifestNormaliser`'s
   `BLOCK_TYPES`/`RENDER_KINDS`/etc. constants so the existing normaliser
   accepts a `portalPage`-sourced contribution unchanged — no normaliser
   code change is required by this schema.

2. **Anonymous submission, first-class.** A collection or action MAY declare
   `anonymous: true`. This is new vocabulary (not present in
   `IPortalContributionProvider` today) and is the mechanism this change adds
   so a `portalPage` (or a PHP provider, since the flag is duck-typed and
   optional like every v2/v3 field) can mark specific entries reachable
   **without any bearer session at all**:
   - `PortalContributionRegistry` gains `aggregateAnonymous(): array` — the
     anonymous-caller sibling of `aggregateFor()`: it enumerates every
     installed provider exactly as today, but instead of filtering by a
     subject's audience/trust, it keeps only collections/actions explicitly
     flagged `anonymous: true` (dropping everything else in the same
     contribution, so an anonymous caller can never see a contribution's
     private entries just because one sibling entry is public) and runs the
     result through the existing `PortalManifestNormaliser` unchanged.
   - `PortalAuthMiddleware::beforeController()` gains an anonymous-allowed
     branch: when no bearer is present, instead of always throwing, it
     checks `aggregateAnonymous()` for an entry matching the request (the
     target `register`/`schema` for `create`, or "any anonymous entry
     exists" for `index`) and lets the request through when one matches;
     otherwise it still throws exactly as today. The bearer-required default
     is unchanged for every entry that does not opt in.
   - `ContributionController::index()` returns the caller's own aggregate
     when a bearer resolves, and now falls back to `aggregateAnonymous()`
     instead of 401 when it does not — giving an anonymous visitor's SPA the
     page layout (labels, richText, field configs) it needs before
     submitting.
   - `ContributionController::create()` gains an anonymous path: when no
     subject resolves, it looks up an anonymous-flagged `type: create`
     action for the requested `(register, schema)` (mirroring
     `authorisedCreateAction()`); on a match it whitelists fields and
     `defaults` exactly as today but writes through a new
     `PortalObjectWriter::createAnonymousObject()` that stamps **no**
     subject/organisation ownership (there is no subject) instead of
     `createObject()`'s subject-stamping path. The write still goes through
     OpenRegister's normal `saveObject()`, so `ObjectCreatedEvent` still
     fires and any declared `x-openregister-flows` on the target schema
     still run — unchanged, automatic, not part of this change.
   - `minTrust` and `anonymous` are mutually exclusive on one entry: an
     entry declaring both is normalised fail-closed (the anonymous flag is
     dropped, so the entry falls back to requiring an authenticated,
     trust-checked bearer) — a malformed manifest must never accidentally
     open trust-gated data to anonymous callers.

3. **Built-in, config-driven provider.** Portaliq's own demo provider
   (`lib/Portal/PortalContributionProvider.php`) already occupies the FQCN
   `OCA\Portaliq\Portal\PortalContributionProvider` and its docblock already
   says "delete it once real contributions exist" — this change replaces its
   hardcoded PHP-array `getContribution()` body with a config-driven read of
   ACTIVE `portalPage` objects (via `PortalObjectReader`, no direct OR client
   in the provider) filtered to the requesting subject's audience/trust (or,
   for `aggregateAnonymous()`, to `status: active` objects with at least one
   `anonymous: true` entry), each converted 1:1 into the same manifest shape
   the hardcoded version returned, then passed through the SAME
   `PortalManifestNormaliser` the registry already invokes. `getAudiences()`
   becomes dynamic (the union of every active `portalPage.audience`) instead
   of the hardcoded `['supplier']`. This is a **replacement of the
   FQCN's current occupant**, not a new/second provider at that FQCN — see
   Open Question OQ1 in `design.md` for the alternative considered and why
   it was rejected.

Net effect: provisioning a citizen-facing portal page — including one an
anonymous citizen can submit to — becomes writing a `portalPage` OpenRegister
object through the standard OR objects API. Zero Portaliq PHP per page.

## Capabilities

### Added Capabilities

- `portal-page-provisioning`: an app (or an admin) MUST be able to provision
  a portal contribution — including anonymous-reachable collections/actions —
  as a `portalPage` OpenRegister object, with zero Portaliq code per page.

## Impact

- `lib/Settings/portaliq_register.json` — new `portalPage` schema (additive;
  no existing schema changes).
- `lib/Portal/PortalContributionProvider.php` — `getContribution()`/
  `getAudiences()` become config-driven reads of `portalPage` objects,
  replacing the hardcoded demo manifest.
- `lib/Contribution/PortalContributionRegistry.php` — new
  `aggregateAnonymous()` method (additive; `aggregateFor()` unchanged).
- `lib/Contribution/IPortalContributionProvider.php` — docblock update
  documenting the new optional `anonymous` field (duck-typed, additive; no
  interface method signature changes — a v1/v2/v3 provider that never sets
  `anonymous` is unaffected).
- `lib/Middleware/PortalAuthMiddleware.php` — anonymous-allowed branch in
  `beforeController()` (additive; every non-anonymous-flagged route is
  gated exactly as today).
- `lib/Controller/ContributionController.php` — `index()` and `create()`
  gain an anonymous fallback/path.
- `lib/Service/PortalObjectWriter.php` — new `createAnonymousObject()`
  (additive; `createObject()` unchanged).
- Not BREAKING: every change is additive and duck-typed/optional. A subject
  with no anonymous-flagged entries anywhere in the fleet sees byte-identical
  behaviour to today.
