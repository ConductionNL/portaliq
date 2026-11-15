# Proposal: instance-loopback-self-calls

## Why

Portaliq calls its own Nextcloud server for a resident's tasks, for endpoint actions and for the availability check. Those calls went to the instance's public absolute URL, for example `http://localhost:8090/index.php/apps/openregister/api/portal-tasks`.

Behind a port mapping (`docker -p 8090:80`), a reverse proxy or split DNS, that address does not answer from inside the server. Every call failed with cURL error 7. Residents read "Uw taken konden niet worden geladen" (502 `task-service-unreachable`).

Seen on the shared test instance on 2026-10-04. It was worked around there by letting Apache also listen on 8090 inside the container. This change makes the workaround unnecessary.

## What changes

- One service, `InstanceLoopback`, sends every request portaliq makes to its own instance.
- It uses, in order:
  1. the internal address an administrator set (app config `portaliq` / `internal_base_url`), when it is a valid http(s) address;
  2. otherwise the absolute URL, as before;
  3. when the absolute URL fails before any HTTP answer (DNS, refused, unreachable, a connect timeout), one retry on `http://127.0.0.1` with the same path and the original `Host` header.
- A real HTTP answer, whatever its status, is never retried. A timeout after the connection was made is never retried, because the request may already have been applied.
- The fallback is logged once per request at info. When both addresses fail, a warning names both failures.
- The address that worked is kept for the rest of the request, never across requests.
- The task gateway (list, detail, complete, create), the action forwarder and the availability probe use the service.
- Administrators set the address in the admin settings under "Calls to this server", or with `occ config:app:set portaliq internal_base_url --value=...`. An invalid address is refused on save and ignored with a warning when it is set some other way.

## Not changed

- The headers each call sends. The signed `X-Portal-Subject` assertion travels as before; the client's own Authorization header is still never forwarded.
- Calls to other servers: the OIDC broker, the integriq envelope exchange (a configured address that may be another server) and the geography database downloads.
- Links shown to people (deep links, redirects, canonical URLs). They keep the public address.
