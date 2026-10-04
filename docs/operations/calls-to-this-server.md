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
2. when that fails before any answer (the name does not resolve, the connection is refused or unreachable, or connecting times out), tries once more on `http://127.0.0.1` with the same path. It sends the original `Host` header, so `trusted_domains` and your virtual host still match;
3. uses 127.0.0.1 directly for the rest of that request.

Portaliq never retries after a real answer from the server, whatever its status. It also never retries a request that timed out after connecting, because the server may already have handled it.

The fallback is logged once per request at info level. When 127.0.0.1 fails too, a warning names both failures.

## Setting the internal address

Set the internal address when 127.0.0.1 is not the right place either, for example when the web server runs in another container. You can also set it to skip the failed first attempt.

In Nextcloud, go to **Administration settings > Portaliq > Calls to this server**, enter the address and save it. Or use occ:

```bash
occ config:app:set portaliq internal_base_url --value="http://nextcloud-app"
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

With an address set, Portaliq uses only that address. It sends the public `Host` header, so the address does not have to be in `trusted_domains`. It does not fall back to 127.0.0.1: if the address fails, the call fails and the log says why.

To go back to the default, clear the field or run:

```bash
occ config:app:delete portaliq internal_base_url
```

## What stays the same

- Links that people see, such as e-mail links, redirects after sign-in and canonical page addresses, keep the public address.
- Calls to other servers, such as the sign-in broker and the geography database download, are not affected.
- Each call sends the same headers as before. The client's own Authorization header is never forwarded.

## Checking it

After a change, sign in to a portal as a resident and open **Mijn taken**. In the Nextcloud log, a line with `[InstanceLoopback]` at info level tells you the fallback is in use. A warning with `[InstanceLoopback]` means neither address answered: set the internal address.
