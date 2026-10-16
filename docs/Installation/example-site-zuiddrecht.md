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

You get one portal, three menus, 39 pages and four news items:

```
Portal: 1 declared, 1 created, 0 already there, 1 found afterwards
Menus: 3 declared, 3 created, 0 already there, 3 found afterwards
Pages: 39 declared, 39 created, 0 already there, 39 found afterwards
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

A fresh site has nobody to sign in as, so the area behind the button stays empty. On a demo or test instance, install the Zuiddrecht example set. It gives the whole demo municipality its people and data, and the site its resident:

```bash
occ openregister:example-set:install zuiddrecht
```

This needs the site (above), OpenRegister and dossiq. OpenRegister holds the cast of the set; portaliq adds its own part from `lib/Settings/example-sets/zuiddrecht.json`. For the site you get:

- Nextcloud accounts for the residents Sanne de Vries (`sanne.devries`), Henk Bakker, Linda Bakker and Fatima El-Amrani, and for the business contacts Jan-Willem van der Berg and Mark Visser. The passwords are shown once, with the other new passwords at the end of the output. Prefer your own? Put it in `OC_PASS` and add `--password-from-env`.
- A portal account for each under the same id, and the mandates that let Linda act for her father Henk and Jan-Willem and Mark for their companies. The site greets a resident by name and asks for an e-mail address, as the design does.
- The group `inwoners` with the four residents in it. The portal names it as its resident group (`authentication.registration.residentGroup`). The group is kept when it already exists, for example because you made it for the registration app, and is not deleted on remove. The business contacts stay out of it: they act for a company, which is eHerkenning.
- The group `ondernemers` with the business contacts Jan-Willem van der Berg, Mark Visser and Karin Smit in it. The portal names it as its business group (`authentication.registration.businessGroup`), so a business contact who signs in to Nextcloud directly lands on the portal, and gets a portal account with audience `supplier` on the first sign-in. The registration app never fills this group.
- A third card on the sign-in page, "Met een account", with the button "Inloggen met uw account", as on the Inloggen board. It opens Nextcloud's own sign-in form, with "Wachtwoord vergeten" and, when the registration app puts new users in `inwoners`, "Account aanmaken" under it. The portal gets the sign-in mode `nextcloud` for it.
- Five cases in dossiq: a Woo request in treatment, a building permit that waits for a drawing, a report about a loose paving stone, and two finished cases. One open question ("Stuur de bouwtekening van uw dakkapel") and four messages belong to them. Every date is counted from the day you install, so the cases stay current.

Open the site, press "Mijn Zuiddrecht", choose "Inloggen met uw account" and sign in as `sanne.devries`. You land on the overview with the question, three running cases and the newest messages. "Mijn zaken" lists them under Lopend and Afgerond. Open a case for its steps, details and messages.

Check the set first, without writing any of it, with `--check`. The install reads everything back and exits 2 when something is missing or a key was not kept. Running it again writes nothing: every part is reported as "already there". Is `sanne.devries` an account you made yourself? The install refuses to touch it. Add `--adopt=sanne.devries` to let the set use it; remove then leaves it in place.

### Moving from the old install

`occ portaliq:example-resident:install` is retired. Did you use it on this instance? Remove that install first, or Sanne gets every case twice:

```bash
occ portaliq:example-resident:remove zuiddrecht
occ openregister:example-set:install zuiddrecht
```

The remove command stays for one release, for exactly this step.

### Who can sign in this way

The cast members of the set, and residents who made their own account. The mode `nextcloud` mints a portal session for a Nextcloud account that has an active portal account under the same id. A member of `inwoners` without one gets it on the first sign-in. Other Nextcloud accounts on the instance get "no_portal_account" and nothing else. The account is a full Nextcloud account, though: it can open Files and the other apps. Keep it to a demo or test instance, and remove it when the demo is over.

### One click on a demo

On a demo the Nextcloud form is one step too many. Switch the one-click sign-in on:

```bash
occ config:app:set portaliq example_resident_demo_login --value=yes
```

The sign-in page then shows one more card per resident, after the other ways in: Sanne, Henk, Linda and Fatima. Each signs the visitor in as that resident with one click, no form, and says "Alleen op deze demo" under its button. Sign in as Linda to show a case she handles for her father. The "Met een account" card stays as it is, so somebody who made an account still signs in with it. A card works only for a resident the set installed, on its own site, while its portal account is active; the route (`/portal/api/session/example-resident`) answers 404 to everything else, is rate limited like the test sign-in, and `debug` mode does not open it. "Uitloggen" ends the portal session as always. Switch it off again with `occ config:app:set portaliq example_resident_demo_login --value=no`, and the resident cards are gone.

### Why not the test sign-in

Portaliq also has a test sign-in, `POST /portal/api/session/dev-login`, which the site offers as the button "Dev-login (test)". It is closed unless an administrator sets `occ config:app:set portaliq dev_login_enabled --value=yes`; `debug` mode does not open it. While it is open, anyone who can reach the instance can mint a session for any subject reference without a password, so never switch it on for a site real residents use. Check with `occ config:app:get portaliq dev_login_enabled`, and close it with `occ config:app:delete portaliq dev_login_enabled`. The button in the site always signs in as `dev-supplier`, not as a named resident. The example resident does not need it.

### Remove the resident

```bash
occ openregister:example-set:remove zuiddrecht
```

This removes the whole set, and exactly what its install recorded: the messages, the question, the portal account and the Nextcloud account. It takes the sign-in mode and its card off the portal again when the set added them. The cases are the exception. OpenRegister keeps a case as an archive record and refuses to delete it, and dossiq keeps a case whose legal term is running. The command names them and exits 2. They stay in dossiq and in the set's record, so a later install uses the same cases instead of writing new ones.

Remove the set before you remove the site.

## What is and is not in the site

- The header, footer, home page and the page "Afval scheiden en ophalen" follow the Zuiddrecht design. The other pages are short, so that no link is dead.
- The home page and the content page use the drawn options the design asks for: the "Let op" strip over the whole width with its link, the search on the band with "Veel gezocht", the task tiles with the design's own icons (three columns, a plain list of six on a phone), the "Openbare informatie" card, the grey "Bestuur en organisatie" band, the postcode form, the boxed table and the button with an arrow. Every option is a block property any page can use; a page that names none of them looks as it did.
- The breadcrumb uses the menu's own words for a page the menu names ("Home › Afval").
- The site is served in the portal's language. A browser that asks for English on a portal that declares only Dutch gets Dutch, dates included.
- Two optional theme tokens set the size of a content page's title (`--nldesign-website-page-title-size`) and the hero watermark's opacity (`--nldesign-website-hero-decoration-opacity`); the site renders without them.
- "Woo-publicaties" and the search box open `/zoeken`, with the search block every portal can place. It finds publications when OpenCatalogi is installed.
- Phone numbers and addresses are made up: `14 0999` (marked as an example number in the footer), `info@zuiddrecht.example` on a domain reserved for examples, and Stadskantoor, Lindelaan 1. Only "Is er direct gevaar?" names a real number, 112, because a visitor in danger must never read a fake one.
- The portal belongs to the OpenRegister organisation `zuiddrecht` (`organisation`), the one the Zuiddrecht example set creates. Portaliq matches it by slug against the portal accounts the set writes.

## Remove

```bash
occ portaliq:example-site:remove zuiddrecht
```

This deletes the objects the install created, from the install's own record, and nothing else:

```
Menus: 3 deleted, 0 already gone
Pages: 39 deleted, 0 already gone
News items: 4 deleted, 0 already gone
Portal: deleted
```

The portal stays when it was there before the install, or when it still holds a menu or a page you added yourself. The command then says so and ends with exit code 2. Delete or move your own content and run it again.

## Next

Change the site into your own: open **Portaliq → Portals**, pick `zuiddrecht` and edit its pages. To give it another house style, see [Choosing a portal's house style](../operations/choosing-a-portal-house-style.md).
