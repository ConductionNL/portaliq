# 0003. Endpoint actions are forwarded server to server with a signed subject assertion

- Status: accepted
- Source: [`openspec/changes/archive/2026-09-07-contract-v2/design.md`](../../openspec/changes/archive/2026-09-07-contract-v2/design.md)
  (section "Endpoint bearer-forward actions (A6)")

## Context

Some portal actions are handled by the contributing app's own endpoint, not by a
portaliq write. The receiving app needs to know which portal subject is acting,
and the visitor's own bearer token must not leak to it.

## Decision

`POST /portal/api/actions/{appId}/{actionId}` forwards only actions the subject's
own, trust-filtered manifest declares; otherwise it answers 403 and makes no
outbound request. The endpoint must be an instance-local absolute path, so a full
`http(s)://` URL is rejected. The forward carries an `X-Portal-Subject` assertion:
a short-lived signed token minted by `PortalJwtService` with `use: "assertion"`.
The client's `Authorization` header is never forwarded. The session resolver
rejects any token carrying `use: "assertion"`, so an assertion can never be
replayed as a portal session. The domain app's status and body are relayed.

## Consequences

- Receiving apps verify the assertion themselves.
- The SSRF guard limits forwarding to this Nextcloud instance.
- A transport failure is reported as 502 `forward_failed`.
