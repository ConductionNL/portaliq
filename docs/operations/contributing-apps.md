---
title: Which apps contribute to the portal
sidebar_label: Contributing apps
description: How to read the census of apps that contribute to the portal, and what "contributes nothing" means
---

# Which apps contribute to the portal

The portal shows only what other apps on the instance contribute to it: a
case app adds the resident's cases, a permits app adds permit requests, and so
on. An app takes part by shipping a contribution provider. An app without one
adds nothing to the portal, and the portal does not say so anywhere.

The census answers the question "why does the portal show no permits?"
without looking at code: is the app missing, does it contribute nothing, or
does it contribute to a different audience?

## Running the census

```bash
occ portaliq:contributions
```

Every enabled app gets one line:

```
dossiq: provider for client (2 collections, 1 pages, 3 actions)
keepiq: contributes nothing
opencatalogi: integrates through its public search and publication endpoints, not a provider
permits: provider failed: RuntimeException
```

For scripts, ask for JSON. It holds the same rows:

```bash
occ portaliq:contributions --output=json
```

Each row has `app`, `status`, `pattern`, `error` and `audiences`. Each
audience has `audience`, `collections`, `pages` and `actions`.

## Reading a line

| Line | Meaning |
| --- | --- |
| `provider for client (…)` | The app ships a provider. It serves the audiences listed, and for each one it declares that many collections, pages and actions. |
| `contributes nothing` | The app is enabled but has no provider, so the portal shows nothing from it. This is not an error: the app has just not been built to contribute. Only the app itself can change this, by shipping a provider. |
| `integrates through its … endpoints, not a provider` | The app is part of the portal in another way. Opencatalogi is one: the portal reads its public search and publication endpoints directly. |
| `provider failed: <class>` | The app has a provider, but asking it threw this exception. The other apps are still listed. The Nextcloud log has a warning for the app. |
| `provider serves no audience` | The app ships a provider that names no audience, so no portal user ever sees it. |

An app that is not on the list is not enabled on this instance.

## What the counts are

The counts come from a made-up subject: one per audience, with only the
audience filled in. The census reads nothing about any real resident. A
provider that shows different things to different people may show a real
person more, or less, than the counts say. Treat a count as "this app declares
something for this audience", not as what one person will see.

The counts are taken before the portal filters them. Items that need a higher
level of trust, and pages that someone has hidden, are still counted here.
