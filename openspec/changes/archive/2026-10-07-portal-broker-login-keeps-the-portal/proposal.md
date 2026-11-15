# Proposal: portal-broker-login-keeps-the-portal

## Why

Found while testing a primary school parent portal end to end (2026-09-30). Portaliq #993 and #999 made a login started on a portal's own address return to `?portal=<slug>`, so the portal keeps its title and branding. That holds for Portaliq's own OIDC route only. A parent whose school routes DigiD to integriq's broker loses the portal:

1. **The broker start drops the portal.** `/portal/api/session/broker/start?portal=<slug>` uses the portal to find the organisation, then stores the plain portal address as the place to return to.
2. **The OIDC start forwards without the portal.** A provider routed to the broker is forwarded from `/portal/api/session/oidc/start` to the broker start with only `org` and `provider`. The public site's sign-in links take this path.
3. **A failed login lands on the plain portal.** The failure message shows without the school's title.
4. **The relay state never comes back.** Portaliq sent it to integriq as `state` and read it back as `state`. Integriq reads and returns `relayState` (digid-eherkenning-auth-adapter REQ-IDP-001), so every broker callback arrived without the state and failed. The two halves were built at the same time, each against its own spec.

## What changes

- The broker start resolves the portal once. It names the organisation when `org` is empty, and the login returns to `?portal=<slug>` of the portal the resolver found. The slug is URL-encoded. An unknown portal returns to the plain portal address, as on the OIDC route.
- The OIDC start forwards the resolved portal's slug to the broker start.
- A failed start lands on the portal it started from with `#signin=failed`. A failed callback lands on the return address stored at the start, when that is a path on this server, else on the plain portal.
- Portaliq sends the relay state to integriq as `relayState` and reads `relayState` back at the callback, falling back to `state`.

## Not changed

- Integriq. Its start already stores the return address and relay state, and its callback already sends both back.
- The failure fragment and its single message (REQ-BEL-006). Only the page it lands on changes.
