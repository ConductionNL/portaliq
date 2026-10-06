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
- From a source checkout only: the built site bundle. `npm run dev` builds the admin screens and nothing else. The public site needs `npx webpack --config webpack.site.js` (and `webpack.traffic.js` for traffic measurement), or `npm run build` for everything. Without it the site is a blank page with one 404 on `portaliq-site.js`. A release from the app store has the bundle.

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

The header shows the button "Mijn Zuiddrecht". It opens the sign-in page with two cards: "Als inwoner" with DigiD and "Namens een bedrijf" with eHerkenning. They work once your instance has a sign-in broker for them: see [Signing in through integriq](../operations/signing-in-through-integriq.md).

After signing in, a resident sees what the installed apps offer. With dossiq that is the overview, "Mijn zaken" and the page of one case with its steps, details and documents. The site calls this area "Mijn Zuiddrecht" everywhere, after the name on the button.

Did you install the site before the sign-in cards were added? The install never changes a portal that exists. Remove the site and install it again.

## The example resident

A fresh site has nobody to sign in as, so the area behind the button stays empty. On a demo or test instance, give the site its resident:

```bash
occ portaliq:example-resident:install zuiddrecht
```

This needs the site (above) and dossiq. You get:

- A Nextcloud account `sanne.devries`, named Sanne de Vries. Its password is shown once, on the last lines of the output. Prefer your own? Put it in `OC_PASS` and add `--password-from-env`.
- A portal account under the same id, so the site greets her by name and asks her for an e-mail address, as the design does.
- A third card on the sign-in page, "Voorbeeldinwoner", with the button "Inloggen als voorbeeldinwoner". It opens Nextcloud's own sign-in form. The portal gets the sign-in mode `nextcloud` for it.
- Five cases in dossiq: a Woo request in treatment, a building permit that waits for a drawing, a report about a loose paving stone, and two finished cases. One open question ("Stuur de bouwtekening van uw dakkapel") and four messages belong to them. Every date is counted from the day you install, so the cases stay current.

```
Nextcloud account sanne.devries: created
Portal account sanne.devries: created
Sign-in mode nextcloud on portal zuiddrecht: added
dossiq case: 5 declared, 5 created, 0 already there, 5 found afterwards
dossiq aanvullingsverzoek: 1 declared, 1 created, 0 already there, 1 found afterwards
dossiq portaalBericht: 4 declared, 4 created, 0 already there, 4 found afterwards
The password of sanne.devries is shown once, here: ...
Sign in at /index.php/apps/portaliq/site?portal=zuiddrecht&route=/mijn with the card "Voorbeeldinwoner".
```

Open the site, press "Mijn Zuiddrecht", choose "Inloggen als voorbeeldinwoner" and sign in as `sanne.devries`. You land on the overview with the question, three running cases and the newest messages. "Mijn zaken" lists them under Lopend and Afgerond. Open a case for its steps, details and messages.

The command reads the instance back like the site install does, and exits 2 when something is missing or a key was not kept. Running it again writes nothing: every part is reported as "already there". Is `sanne.devries` taken on your instance? The command refuses to touch an account it did not make. Give it another id with `--user demo-inwoner`.

### Who can sign in this way

Only the example resident. The mode `nextcloud` mints a portal session for a Nextcloud account that has an active portal account under the same id, and the install makes exactly one. Other Nextcloud accounts on the instance get "no_portal_account" and nothing else. The account is a full Nextcloud account, though: it can open Files and the other apps. Keep it to a demo or test instance, and remove it when the demo is over.

### One click on a demo

On a demo the Nextcloud form is one step too many. Switch the one-click sign-in on:

```bash
occ config:app:set portaliq example_resident_demo_login --value=yes
```

The card "Voorbeeldinwoner" then signs the visitor in as the example resident with one click, no form, and says "Alleen op deze demo" under its button. It works only for the installed example resident, on its own site, while its portal account is active; the route (`/portal/api/session/example-resident`) answers 404 to everything else, is rate limited like the test sign-in, and `debug` mode does not open it. "Uitloggen" ends the portal session as always. Switch it off again with `occ config:app:set portaliq example_resident_demo_login --value=no`, and the card goes back to the Nextcloud form.

### Why not the test sign-in

Portaliq also has a test sign-in, `POST /portal/api/session/dev-login`, which the site offers as the button "Dev-login (test)". It is closed unless the instance runs in `debug` mode or an administrator sets `occ config:app:set portaliq dev_login_enabled --value=yes`. While it is open, anyone who can reach the instance can mint a session for any subject reference without a password, so never switch it on for a site real residents use, and know that `debug` mode opens it too. The button in the site always signs in as `dev-supplier`, not as a named resident. The example resident does not need it.

### Remove the resident

```bash
occ portaliq:example-resident:remove zuiddrecht
```

This deletes what the install recorded: the messages, the question, the portal account and the Nextcloud account, and takes the sign-in mode off the portal when the install added it. The cases are the exception. OpenRegister keeps a case as an archive record and refuses to delete it, and dossiq keeps a case whose legal term is running. The command names them and exits 2. They stay in dossiq, where a colleague sees them as demo cases; no resident can open them, because the accounts are gone. Install the resident again under the same id and the command uses the same cases instead of writing new ones.

Remove the resident before you remove the site.

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
