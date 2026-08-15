---
kind: mixed
---

# Proposal: portal-website-scoping-and-auth

## Summary

Make `website` the unit Portaliq serves: resolve a site from the request `Host`,
scope every content read and write to it, bind verified custom domains, resolve
per-site presentation and theme, and declare per-site authentication. Closes
the `frame-ancestors: '*'` hole by deriving the header per site.

Chain link 5 of `hydra/openspec/changes/portaliq-phase-two`. Implements the
Portaliq half of ADR-086 §§2, 8, 11.

## Motivation

Portaliq already white-labels, but per **Organisation**.
`PortalPageController::index()` resolves a runtime config through
`PortalOrganisationConfigService::resolve()` from a `?org=` slug, injects it
via `IInitialStateService`, and builds `frame-ancestors` from that tenant's
configured embed origins — clearing the `'self'` default first so a tenant with
no configured origins gets deny, not same-origin.

> An earlier draft of this proposal said none of that was built, quoting the
> `portal-white-label-runtime-config` change. That change describes the problem
> as it stood *before* it was fixed; the fix has since landed. Verified in the
> working tree on 2026-08-15 — the service exists and no wildcard
> `frame-ancestors` remains anywhere in `lib/`.

The gap is one level down. A tenant gets one look, one identity-provider
configuration and one set of embed origins, so a municipality cannot run a
public site and a supplier portal that are branded and authenticated
differently — which is exactly what `tilburg-woo-ui` is forked ten ways to do.
And because resolution keys on a `?org=` query parameter rather than the host
the visitor typed, a custom domain has nothing to attach to.

A `website` object gives those fields a home one level below the tenant, and
makes the host the way a site is found. An Organisation with a single website
behaves exactly as it does today.

## Affected Projects

- [ ] `portaliq` — site resolution middleware; domain binding + DNS
      verification; runtime config resolved per site; per-site authentication
      configuration and session scoping; per-site CSP.
- [ ] `procest`, `pipelinq`, `shillinq` — contributions gain a website target.
      The ADR-046 contract is unchanged; no controller or provider signature
      moves.

## Design notes

**No default site.** An unresolved `Host` is 404. A fallback is precisely how a
multi-tenant host serves one tenant's content under another's domain.

**`Host` becomes security-relevant**, so it is taken from the trusted proxy
configuration, not from the client-supplied header.

**Domain binding requires proof of control** — a Portaliq-issued
`TXT _portaliq-verify.<domain>` with a per-website nonce, resolved before the
binding goes live. Without it, pointing DNS at Portaliq is enough to claim any
hostname.

**Authentication is a closed set**: `public`, `nextcloud`, `local`, `oidc`,
`digid`, `eherkenning`, `eidas`. `oidc` covers Google, Microsoft and Keycloak
as one integration. Missing or malformed configuration fails closed to
`public` read-only — not to "authenticated" (which would lock a site out over a
typo) and not to public read-write (which would be an open write surface).

## Risks

- **This change makes Portaliq a multi-tenant host.** Site resolution, domain
  verification and session scoping are each load-bearing; a defect in any one
  crosses a tenant boundary.
- **A session valid across sites is a cross-tenant session.** Scoping is
  asserted, not assumed.
- **The verification checker must be tested in both directions.** One that
  always fails is indistinguishable from one that works if only the failure
  case is exercised.
