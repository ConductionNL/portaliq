---
title: Issuing accounts, invitations and registration
sidebar_label: Accounts and registration
description: How staff issue portal accounts at the desk, invite an address, withdraw what was sent by mistake, and decide who may register
---

# Issuing accounts, invitations and registration

Not everyone can sign in with DigiD or eHerkenning. You can give them a portal account yourself. You can also invite an address, or let people register on the portal and approve them.

Everything on this page needs the right to provision portal accounts (`portal.provision`). Without it, Portaliq tells you so and changes nothing.

## Issue an account at the desk

Open **Accounts** and choose **Issue an account** from the actions menu.

- Fill in the organisation the account belongs to.
- Give an identity reference, such as a BSN or KVK number, or an e-mail address.
- Tick **I checked this address with its owner** only when you did.

The account waits until its owner signs in for the first time. Their first sign-in with a matching identity or checked address activates it.

If an account already exists for that identity, the dialog says so and no second account is made.

The generic **Add** button is gone from **Accounts** on purpose. An account made through the object form skips the check for a duplicate identity.

## Invite someone

Choose **Invite someone** on **Accounts** or on **Invitations**. Portaliq mails a link to the address. You see that it was sent and until when it is valid. You never see the link itself.

**Invitations** lists every invitation with its address, state, sent date, expiry and sender.

To stop a link from working, choose **Withdraw invitation** on its row. The link then admits nobody. An invitation that was already accepted cannot be withdrawn.

## Withdraw an account

Open the account in **Accounts**. While nobody has signed in with it, the account shows **Withdraw this account**. Give a reason. The reason stays on the account.

An account that is in use cannot be withdrawn here.

## Decide who may register

Open the portal in **Portals** and scroll to **Registration**. Choose who may make an account:

- **Nobody**: staff issue or invite every account.
- **Anyone, after a staff member approves**: the account waits for a decision.
- **Anyone who confirms their e-mail address**: the account waits for a mail the registrant confirms.

Under **Allowed e-mail domains** you can list domains, one per line. An address outside them is refused. Leave the list empty to allow every address.

The setting is saved on the portal record, so only an administrator can change it.

## Approve or refuse a registration

Under **Waiting for approval** you see the registrations of the portal's organisation that wait for a decision.

- **Approve** activates the account. The address was not verified by a mail, so the list says so.
- **Refuse** asks for a reason and withdraws the account.

Once decided, the registration leaves the list.

## What visitors see on the sign-in screen

The sign-in screen of the portal's site (under **My page**) shows extra ways in only when the path behind them works.

- **Create an account** appears when the registration policy is not **Nobody** and the organisation offers the e-mail sign-in (the `generic` provider). DigiD, eHerkenning and eIDAS sign people in on a BSN, a KVK number or a foreign identity, never on an e-mail address, so an account made by registration could not sign in with them. Without the e-mail sign-in the door stays hidden.
- **Follow a case with its case number** appears when at least one of the portal's published form bindings names a case type that admits the reference kind. The form lists exactly those case types.

Registration passes the portal's challenge against automated sign-ups. If the portal names a honeypot field, the form carries it empty.

Under **Anyone who confirms their e-mail address**, Portaliq mails an activation link. Following it activates the account and confirms the address. The link works once and for two days. Registering again with the same address sends a new link.

After registration or an accepted invitation, the screen tells the person to sign in with the e-mail sign-in. Their first sign-in with that address finds the account.

A case number and an e-mail address open one case only. The address must be the one recorded on the case. The mailed link works once, and the case opens read only for a short session. It does not show any other case.

The activation, invitation and case-number mails link to the portal's site, where these screens are.

To try it, set the policy to **Anyone who confirms their e-mail address**, add the e-mail sign-in, and open the site's **My page** signed out.
