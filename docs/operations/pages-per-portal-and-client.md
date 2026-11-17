---
title: Pages per portal and per client
sidebar_label: Pages per portal and per client
description: How an administrator chooses which contributed pages a portal's menu shows and in which order, and how a clerk hides pages and their records for one client
---

# Pages per portal and per client

Every installed app can contribute pages to the signed-in part of a portal: quotes, invoices, cases, documents. You choose per portal which of them the menu shows and in which order, and per client account which pages that one client does not see at all.

Nothing changes until someone chooses. A portal without a choice and an account without a choice see exactly what they saw before.

## Choose the menu of one portal

1. Open **Portals** and open the portal.
2. Open **Instellingen** and go to **Navigation**.
3. The pages are listed per audience (for example `client` or `supplier`), in the order the menu shows them.
4. Switch off **Show in this portal** to leave a page out of this portal's menu.
5. Use the up and down buttons to move a page.
6. Choose **Save**.

A page installed after you saved a choice is listed after the pages you placed, and it is shown until you hide it. So a new app is never hidden by an older choice.

### Hiding a page on a portal is not access control

A page you hide on a portal is only left out of that portal's menu. The same client may still read those records through another portal of your organisation where the page is shown. To stop one client reading them, hide the page on the client's account, below.

## Hide pages for one client

1. Open **Accounts** and open the client's account.
2. Go to **Pages hidden for this client**.
3. The pages the installed apps contribute for the account's audience are listed. Switch on **Hide for this client** for each page this client must not see.
4. Choose **Save**.

For that account:

- the page is gone from the menu, on every portal;
- every collection that only that page shows is closed: the portal refuses it for this account, also when it is called directly;
- a collection another, still visible page shows stays readable.

Other accounts are not affected. The client cannot change the list themselves: it is set by staff only. A page you hid for an app that is no longer installed stays listed, marked "no longer installed", so you can show it again.

Who may do this: staff allowed to manage portal accounts (the same permission as provisioning an account).
