# Design: portal-contribution-endpoint-actions

## Problem

Two action types were designed into the manifest contract
(`IPortalContributionProvider`'s docblock lists `endpoint` alongside
`id`/`label`), but the read/render path
(`ContributionController::create()` + `authorisedCreateAction()`) only ever
handles `type: "create"`. `endpoint`-type actions exist only as an inert,
always-disabled button in the React shell. This blocks any domain-app action
that isn't a plain OR object create — which is most of them: procest's own
planned `requestRenewal` / `submitAccreditation` are business operations on
procest's existing supplier API, not new OR rows.

## Two ways to forward a request

1. **Portaliq calls the domain app's endpoint server-side** (server-to-server
   forward): Portaliq's `ContributionController` receives the request, looks
   up the declared endpoint, and issues an internal HTTP call to the domain
   app carrying an internally-minted, short-lived assertion of
   `subjectRef`/`organisation` (NOT the visitor's portal bearer directly —
   procest's own auth domain doesn't know how to validate a Portaliq JWT).
2. **Portaliq redirects the browser to call the domain app directly**,
   attaching the portal bearer, and the domain app validates it itself.

**Decision: (1), server-to-server forward.** It keeps the domain app's
existing auth boundary (procest's `SupplierAuthMiddleware` already expects its
own token shape per DC03) unchanged, and it keeps the subject-scoping
guarantee (server-derived, never client-supplied) inside Portaliq's trust
boundary end-to-end — the same posture `PortalObjectWriter` already uses for
`create` actions. Option (2) would require every contributing app to learn to
validate Portaliq's JWT format, coupling their auth domains together, which
ADR-046's whole premise (each app owns its own domain, Portaliq owns only the
edge + aggregation) argues against.

## Endpoint action manifest shape

```json
{
  "id": "requestRenewal",
  "type": "endpoint",
  "label": "Request renewal",
  "appId": "procest",
  "path": "/api/leverancier-portaal/renewals",
  "method": "POST",
  "fields": ["contractId", "note"]
}
```

`appId` + `path` are declared by the contributing app itself (in its own
`PortalContributionProvider::getContribution()`), same trust model as
`register`/`schema` on `collections` — the contributing app is trusted to
declare its own action correctly; Portaliq's job is only to (a) confirm the
action is present in the subject's own aggregated contributions before
invoking it (identical to `authorisedCreateAction()`) and (b) whitelist fields
before forwarding.

## Server-to-server call shape

Portaliq resolves the target app's base URL the same way any internal NC
app-to-app call would (routed through NC's own HTTP client / internal
service discovery — no new external dependency). It attaches:

- the whitelisted `fields` as the request body;
- a short-lived, Portaliq-signed internal assertion carrying `subjectRef` +
  `organisation` (reusing `PortalJwtService` — same signing mechanism, a
  distinct `audience: "internal-forward"` claim so it can never be confused
  with an external-facing session token, and a very short TTL, e.g. 30s).

The receiving app (procest) is expected to trust this internal assertion the
same way its supplier API already trusts its own bearer — validating the
signature against a **shared, Portaliq-specific forwarding secret** configured
on both sides (out of scope to configure in this change; tracked as `T10` on
procest's side). Until a receiving app opts in, `invoke()` returns `502` (the
same "domain app unreachable/refused" shape `PortalObjectWriter::createObject()`
already uses for OR write failures).

## Non-goals

- Two-way response streaming / long-running actions — the forward is a single
  request/response, matching `create()`'s shape.
- Configuring procest's receiving side — that is procest's own repo's
  responsibility (`supplier-portal` T10).
