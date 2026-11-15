---
title: Calls to this server
sidebar_label: Calls to this server
---

# Calls to this server

Portaliq calls its own Nextcloud server for some of its work. It loads and completes a resident's tasks in OpenRegister. It forwards endpoint actions to the app that owns them. It checks every published portal for the availability report.

These calls leave PHP and come back in through the web server. That only works when the server can reach itself at the address it uses.

## The problem this solves

By default a call goes to the instance's public address, for example `https://portal.example.nl/index.php/apps/openregister/api/portal-tasks`. Inside the server that address does not always answer:

- **Port mapping.** Docker publishes port 80 as 8090 (`-p 8090:80`). Inside the container nothing listens on 8090.
- **Reverse proxy.** The public name points at the proxy, and the server may not be allowed to reach it.
- **Split DNS.** The public name resolves to something else, or to nothing, inside your network.

The call then fails with cURL error 7 and the resident sees "Uw taken konden niet worden geladen".

## What Portaliq does by itself

Without any setting, Portaliq:

1. calls the public address, as it always did;
2. when that fails before any answer (the name does not resolve, the connection is refused or unreachable, or connecting times out), tries the addresses the server may answer on from inside itself, one after the other, with the same path:
   - the address and port the web server took the current request on, as PHP sees them (`SERVER_ADDR` and `SERVER_PORT`). This reaches a web server in a sidecar or another container that listens on a port other than 80, such as nginx on 8080 in front of PHP-FPM;
   - `127.0.0.1` on that same port;
   - the address in `overwrite.cli.url`;
   - `http://127.0.0.1`.

   It sends the original `Host` header, so `trusted_domains` and your virtual host still match. It moves to the next address only when the previous one did not connect;
3. uses the address that answered for the rest of that request.

Portaliq never retries after a real answer from the server, whatever its status. It also never retries a request that timed out after connecting, because the server may already have handled it.

The fallback is logged once per request at info level, with the address that answered. When none of the addresses answers, one warning names every failure.

Background jobs, such as the availability probe, do not run through the web server, so they only have `overwrite.cli.url` and `http://127.0.0.1` to fall back on. When your web server does not listen on port 80 inside the server, set the internal address.

## Setting the internal address

Set the internal address when none of the addresses above is the right place, for example when the web server runs in another container and background jobs need to reach it. You can also set it to skip the failed first attempts.

In Nextcloud, go to **Administration settings > Portaliq > Calls to this server**, enter the address and save it. Or use occ:

```bash
occ config:app:set portaliq internal_base_url --value="http://nextcloud-app"
```

When nginx runs next to PHP-FPM in the same pod and listens on 8080, as on the Conduction platform:

```bash
occ config:app:set portaliq internal_base_url --value="http://127.0.0.1:8080"
```

Include the web root when Nextcloud runs under a path, the same way as `overwrite.cli.url`:

```bash
occ config:app:set portaliq internal_base_url --value="http://nextcloud-app/nextcloud"
```

The address must:

- start with `http://` or `https://` and name a host, with an optional port;
- carry no user name or password, no query and no fragment;
- carry no `.` or `..` path segments, no `%` encoding, no spaces and no backslashes.

The settings page refuses an invalid address and keeps the one already stored. When an invalid value gets in another way, Portaliq ignores it, logs a warning and falls back to the default behaviour.

With an address set, Portaliq uses only that address. It sends the public `Host` header, so the address does not have to be in `trusted_domains`. It does not fall back to any other address: if the address fails, the call fails and the log says why.

To go back to the default, clear the field or run:

```bash
occ config:app:delete portaliq internal_base_url
```

## What stays the same

- Links that people see, such as e-mail links, redirects after sign-in and canonical page addresses, keep the public address.
- Calls to other servers, such as the sign-in broker and the geography database download, are not affected.
- Each call sends the same headers as before. The client's own Authorization header is never forwarded.

## Checking it

After a change, sign in to a portal as a resident and open **Mijn taken**. In the Nextcloud log, a line with `[InstanceLoopback]` at info level tells you the fallback is in use. A warning with `[InstanceLoopback]` means no address answered: set the internal address.
