---
title: Signing in with DigiD, eHerkenning or eIDAS through integriq
sidebar_label: Sign-in through integriq
description: How an administrator routes a portal's DigiD, eHerkenning or eIDAS login to integriq's broker, and what integriq must provide first
---

# Signing in with DigiD, eHerkenning or eIDAS through integriq

A resident signs in with DigiD, a business user with eHerkenning, and a visitor from another EU country with eIDAS. Portaliq does not speak to these government logins itself. Per organisation and per provider, an administrator picks one of two routes:

- **The organisation's own sign-in service (OIDC)**: the organisation runs an OIDC broker in front of the government login. This is the route every organisation had before, and it stays the default.
- **Integriq**: integriq talks to the government login and hands portaliq a signed subject envelope. The organisation needs no broker of its own.

## What integriq must provide first

On the integriq side, an administrator of integriq:

1. turns its identity broker on and gives it a signing key;
2. registers portaliq as a consumer, with a consumer id and a secret;
3. offers a start address that accepts `organisation`, `provider`, `trust`, `consumer`, `state` and `returnUrl`, signs the person in, and sends the browser back to `returnUrl` with the same `state` and a one-time `code`.

Portaliq redeems that code at integriq's exchange address (`POST /apps/integriq/api/idp/envelope/exchange`) with the consumer secret. Until integriq's start address exists, keep the OIDC route: portaliq refuses a broker route without one, so residents never see a button that fails.

## Choosing the route

Open **Portals**, then a portal, and find the **Sign-in** widget. The settings belong to the portal's organisation and apply to all its portals.

1. For each of DigiD, eHerkenning and eIDAS, choose the organisation's own sign-in service or integriq.
2. Under **Integriq**, fill in the start address, the exchange address, the consumer id and the consumer secret. Both addresses must start with `https://`.
3. Press **Save**. "The sign-in settings are saved."

A provider set to integriq without every broker setting is not saved: "A provider can only use integriq once the start address, the exchange address, the consumer id and the secret are all set."

The secret is write-only. The screen says whether one is stored; typing a new one replaces it, and leaving the field empty keeps it.

**Accounts do not carry over between routes.** Integriq gives each resident a pseudonym per organisation, and an organisation's own broker gives another identifier. A resident who signed in before gets a new account after the route changes.

## The addresses a broker must know

Both routes send the browser back to portaliq. A broker that checks those addresses against a list refuses any address you did not register. Put your server's address in front of each path below. Nextcloud adds `/index.php` unless pretty URLs are switched on. Register the form your residents see in their browser.

| Route | The broker's name for it | Path |
|---|---|---|
| The organisation's own sign-in service (OIDC) | redirect URI | `/index.php/apps/portaliq/portal/api/session/oidc/callback` |
| The organisation's own sign-in service (OIDC) | post-logout redirect URI | `/index.php/apps/portaliq/site` |
| Integriq | return address (`--return-url`) | `/index.php/apps/portaliq/portal/api/session/broker/callback` |

**Sign-out now returns to the site.** It used to return to `/index.php/apps/portaliq/portal`. That address now only forwards to the site, and portaliq no longer sends it. An OIDC broker that compares `post_logout_redirect_uri` with a list refuses the sign-out until you add the site's address. You can then remove the old one.

**Integriq takes no sign-out address.** Signing out of a session that came through integriq ends the portal session. Integriq has no sign-out step yet, so there is nothing to register there. The integriq return address did not change.

## What a resident sees

The login screen shows the same **Log in with DigiD** button on either route. A login through integriq ends on the portal, signed in, with the trust level integriq reported (low, substantial or high). A trust level portaliq does not know counts as low.

A login that fails for any reason, from a refused code to an envelope meant for another organisation, lands on the login screen with one sentence: "Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier." The page never says why, so it tells nobody which check failed.

## What portaliq checks

Before it acts on an envelope, portaliq checks that it is a subject envelope from integriq's broker, meant for this consumer, this organisation and this provider, not expired and not older than 60 seconds. The code is redeemed once: a second use of the same callback is refused before integriq is asked. The envelope is never stored and never used as a session; portaliq mints its own session from it.
