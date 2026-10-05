---
title: Install the Zuiddrecht example site
sidebar_label: Zuiddrecht example site
description: Put the website of the demo municipality Zuiddrecht on your instance with one command, and take it off again
---

# Install the Zuiddrecht example site

Zuiddrecht is a municipality that does not exist. Its website shows what a portal looks like when it is finished: a header with search and a button to the resident's own area, a home page with top tasks and news, content pages and a footer. You install it with one command and remove it with one command.

## What you need

- Portaliq and OpenRegister, both enabled.
- The theme app (thematiq) with the `zuiddrecht` set. Without it the site installs and shows without its house style. The command tells you when the set is missing.

## Install

```bash
occ portaliq:example-site:install zuiddrecht
```

You get one portal, three menus, 33 pages and four news items:

```
Portal: 1 declared, 1 created, 0 already there, 1 found afterwards
Menus: 3 declared, 3 created, 0 already there, 3 found afterwards
Pages: 33 declared, 33 created, 0 already there, 33 found afterwards
News items: 4 declared, 4 created, 0 already there, 4 found afterwards
The site is installed. Open it at /index.php/apps/portaliq/site?portal=zuiddrecht
```

Open that address in a browser. You do not have to sign in.

The command only writes what is missing. A portal with the slug `zuiddrecht`, a menu with the same position and title, a page at the same route and a news item with the same title stay exactly as they are. So you can run it again after an editor changed a page: the change stays.

## Read the result

After writing, the command reads your instance back and compares it with what it meant to write. The last number on each line is what it found.

| Exit code | Meaning |
| --- | --- |
| 0 | Everything is on the instance. |
| 1 | Nothing was written: the site name is unknown, or OpenRegister is not available. |
| 2 | Something is missing. Each missing object and each lost key is named. |

A line such as `Written but not kept: portal zuiddrecht: headerSearch.label` means the portaliq register on your instance is older than the app. Run `occ maintenance:repair` and install again.

## Open the site on your own address

The site answers at `/index.php/apps/portaliq/site?portal=zuiddrecht`. To serve it on a hostname of its own, add the hostname to the portal and verify it. [Custom domains](../operations/custom-domains.md) explains how. The install never claims a hostname for you.

## Signing in

The header shows the button "Mijn Zuiddrecht". The portal names DigiD and eHerkenning as its ways in, as the design does. They work once your instance has a sign-in broker for them: see [Signing in through integriq](../operations/signing-in-through-integriq.md). On a test instance you can switch on the test sign-in instead:

```bash
occ config:app:set portaliq dev_login_enabled --value=yes
```

Never switch that on for a site real residents use.

## What is and is not in the site

- The header, footer, home page and the page "Afval scheiden en ophalen" follow the Zuiddrecht design. The other pages are short, so that no link is dead.
- "Woo-publicaties" and the search box open `/zoeken`, with the search block every portal can place. It finds publications when OpenCatalogi is installed.
- Phone numbers and addresses read `[telefoonnummer]`, `[e-mailadres]` and `[adres]`. Zuiddrecht has none.

## Remove

```bash
occ portaliq:example-site:remove zuiddrecht
```

This deletes the objects the install created, from the install's own record, and nothing else:

```
Menus: 3 deleted, 0 already gone
Pages: 33 deleted, 0 already gone
News items: 4 deleted, 0 already gone
Portal: deleted
```

The portal stays when it was there before the install, or when it still holds a menu or a page you added yourself. The command then says so and ends with exit code 2. Delete or move your own content and run it again.

## Next

Change the site into your own: open **Portaliq → Portals**, pick `zuiddrecht` and edit its pages. To give it another house style, see [Choosing a portal's house style](../operations/choosing-a-portal-house-style.md).
