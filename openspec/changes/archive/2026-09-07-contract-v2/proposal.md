---
kind: code
---

# Proposal: contract-v2

## Summary

Implement **contribution contract v2** in Portaliq per the ADR-046 amendment of
2026-07-06 (hydra, merged): multi-audience providers (A2), eIDAS-aligned trust
levels with fail-closed server-side enforcement (A3), a server-managed per-app
claim map on `portalAccount` plus `scopeClaim` collection scoping (A4), one-hop
`via` join scoping (A5), and endpoint bearer-forward actions with a short-lived
signed `X-Portal-Subject` assertion (A6). Contract v1 is proven live
(supplier-portal change, procest pilot); v2 closes the six gaps the fleet
review (`PORTALIQ-FLEET-REVIEW-2026-07-06.md`) found blocking the nine Tier-1
contributors. This change covers the **portaliq side only** — providers in
domain apps and assertion verification by receiving apps land as per-app
`portal-contribution` changes.

## Motivation

Contract v1 binds a provider to a single audience, has no trust gating, can
only scope a collection by the subject's own pseudonymous `subjectRef` on a
direct property, and only supports `type: create` (OR-write) actions. The
fleet review showed real contributors need more: pipelinq alone serves client
+ supplier + reseller audiences; decidesk QES signing and scholiq submissions
are trust-gated; pipelinq scopes by a linked contact (not the subjectRef);
ZGW-style domains (zaakafhandelapp) scope a `zaak` indirectly through a `rol`
row; and domain actions (accept quote, cast vote, sign) must execute in the
domain app, not as an OR write. The amendment is merged and normative; Wave 0
of the rollout is "portaliq implements A2–A6" — this change.

## Affected Projects

- [ ] Project: `portaliq` — registry (multi-audience + trust filtering), object
  reader (scopeClaim / via / minTrust), object writer (minTrust), new
  action-forward endpoint + short-lived subject assertion, `portalAccount`
  schema gains a server-managed `claims` property (register version bump).

Other projects (procest, pipelinq, shillinq, hrmq, decidesk, zaakafhandelapp,
docudesk, softwarecatalog, scholiq, nextcloud-vue A7) adopt the contract in
their own repos in later waves — no code in this change.

## Scope

### In Scope

- **A2**: `PortalContributionRegistry` prefers duck-typed `getAudiences(): array`
  when present, falls back to `getAudience(): string`; a provider is consulted
  when the subject's audience is in its audience list.
- **A3**: trust ordering `low < substantial < high`; `minTrust` on collections
  and actions; below-threshold entries filtered out of the aggregated manifest
  and denied fail-closed on read, create, and endpoint-action forward.
- **A4**: `portalAccount.claims` (`{appId: {claimName: uuid}}`, server-managed);
  `scopeClaim` on a collection selects the scoping value resolved server-side
  from the subject's `portalAccount`; absent claim → collection contributes
  nothing (fail-closed empty).
- **A5**: `via: {register, schema, scopeField, targetField}` one-hop join
  scoping in the reader, same `_rbac: false` / `_multitenancy: false` +
  per-row verification discipline as direct reads.
- **A6**: `POST /portal/api/actions/{appId}/{actionId}` — validate the action
  against the subject's own aggregated manifest (403 otherwise), forward
  server-to-server with a ~60s HS256 `X-Portal-Subject` assertion, relay the
  response.
- Register config: the `portalAccount` `claims` property + version bump in
  `lib/Settings/portaliq_register.json` (~6 lines; see design.md
  "Mixed-spec rationale").
- Unit tests for every new behaviour (fail-closed matrices), demo-provider
  updates so v2 vocabulary is exercisable, seed claims example.

### Out of Scope

- Domain-app providers (procest/pipelinq/… `portal-contribution` changes) and
  **assertion verification in receiving apps** (A6 receiver side).
- A7 (OpenBuild/manifest `portal` section — nextcloud-vue schema bump).
- The real eHerkenning/DigiD broker (T02 remainder of `supplier-portal`);
  trust values keep coming from the session mint (dev-login → `low`).
- Claims administration UI (claims are written by portaliq server code /
  admin tooling later; this change defines storage + resolution only).
- Multi-hop `via` chains (amendment: one hop maximum, by design).

## Approach

Extend the existing v1 seams in place — no new architecture: registry
aggregation gains audience-list + trust filtering; the reader gains a scope
value resolver (subjectRef | claim) and an optional join pre-pass; the writer
and create-authorisation gain a trust re-check; a new thin controller method
implements the action forward using the existing `PortalJwtService` secret
sourcing for the assertion. Everything fails closed (ADR-005): unknown trust →
`low`; unresolvable claim → empty collection; unauthorised action → 403.
Details in design.md.

## Capabilities

### New Capabilities

- `portal-contribution-contract`: the v2 contribution contract as enforced by
  portaliq — multi-audience provider discovery, trust-level filtering and
  enforcement, claim-based scoping, one-hop via scoping, and endpoint
  bearer-forward actions (delta spec
  `specs/portal-contribution-contract/spec.md`).

### Modified Capabilities

- None (v1 behaviour lives in the still-open `supplier-portal` change; its
  artifacts are not touched — v2 requirements are additive and live in the new
  capability spec, which is the canonical home of the contract from now on).

## New Dependencies

None. The action forward uses Nextcloud's own `OCP\Http\Client\IClientService`;
the assertion reuses the existing HS256 signing path (instance secret via the
`PortalSessionService` secret sourcing). No new packages.

## Impact

- `lib/Contribution/PortalContributionRegistry.php` — audience list + trust
  filtering (behavioural, backward compatible with v1 providers).
- `lib/Contribution/IPortalContributionProvider.php` — document v2 optional
  methods (duck-typed; interface stays optional for contributors).
- `lib/Service/PortalObjectReader.php` — scope-value resolution (claims),
  `via` join pre-pass, dot-path field access.
- `lib/Service/PortalObjectWriter.php` / create path — minTrust enforcement.
- `lib/Controller/ContributionController.php` — trust-filtered manifest,
  defense-in-depth re-checks, new `action()` forward method.
- `lib/Service/PortalJwtService.php` — short-lived assertion mint (distinct
  from session tokens; session resolver rejects assertions).
- `lib/Settings/portaliq_register.json` — `portalAccount.claims` + version bump.
- `appinfo/routes.php` — one new route.
- v1 manifests keep working unchanged: every v2 field is optional with
  fail-open-to-v1 defaults (`minTrust` absent = `low`, `scopeClaim` absent =
  subjectRef, `via` absent = direct read).

## Cross-Project Dependencies

- **Consumes**: OpenRegister objects API (unchanged usage pattern).
- **Consumed by (later waves)**: the nine Tier-1 contributor apps implement
  providers against this contract; receiving apps verify the `X-Portal-Subject`
  assertion. The contract's canonical definition is the ADR-046 amendment
  (hydra `openspec/architecture/adr-046-portaliq-external-portal.md`) — this
  change implements it, it does not fork it.

## Risks

### Risk 1: Token confusion between session bearers and subject assertions

**Severity:** High — **Mitigation:** assertions carry a distinct `use:
"assertion"` claim and a ~60s TTL; `PortalSessionService::resolveFromBearer`
rejects any token carrying it, so a relayed assertion can never be replayed as
a portal session. Covered by a fail-closed unit scenario.

### Risk 2: `via` join amplification / cross-subject leakage

**Severity:** Medium — **Mitigation:** one hop maximum (deeper declarations are
rejected fail-closed), join rows are per-row verified against the scope value
before targets are read, target rows are kept only when their id/uuid is in
the verified join set, and the join pre-pass is row-capped.

### Risk 3: Claims map becomes client-writable by accident

**Severity:** Medium — **Mitigation:** `claims` lives on `portalAccount`
(`publicRead/publicWrite: false`), is resolved only server-side, and the
create/write whitelist path never accepts a `claims` field from a client.

### Risk 4: v1 contributors break on v2 registry changes

**Severity:** Low — **Mitigation:** duck-typed fallback to `getAudience()`;
all new manifest fields optional with v1-equivalent defaults; existing unit
suite must stay green.

## Rollback Strategy

Revert the portaliq PR. The register change is additive (an optional `claims`
object property); reverting the JSON and re-importing restores the prior
schema version without data loss — existing `portalAccount` objects simply
keep an ignored extra property until pruned. No NC database migrations are
involved (thin-client app, ADR-001).

## Open Questions

None blocking — the amendment is merged and normative. Provisional decisions
made while drafting (scopeClaim addressing format, assertion anti-replay
claim, join-row cap) are recorded in design.md "Decisions".
