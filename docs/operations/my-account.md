---
title: My account
sidebar_label: My account
description: How residents and business users keep their own name, e-mail addresses and phone numbers, choose how you contact them, and remove their portal account
---

# My account

A signed-in resident or business user opens **My account** in the portal. There they change their name, keep several e-mail addresses and phone numbers, choose how your organisation contacts them, and remove their portal account.

## E-mail addresses

A new e-mail address is used for nothing until the person confirms it. The portal sends a mail with a link to the new address; the link works once, for one day. Until the link is followed, notifications keep going to the address the account already had.

A person can keep several addresses. One confirmed address is marked **Preferred**, and that is where notifications go. An address that waits for confirmation cannot be preferred: the portal says "Confirm this address first." At most five addresses can wait for confirmation at the same time.

The preferred address cannot be removed while another confirmed address exists. The person picks the next preferred address first.

## Phone numbers

Phone numbers are stored for your organisation to call. The portal writes a Dutch number such as 06 1234 5678 as +31612345678. It sends no text message and does not check the number.

## How you contact them

The person chooses one channel: only through the portal, by e-mail, by phone or by post. An account without a choice reads as "only through the portal".

The portal itself sends no letter and makes no call. Each change raises the event `OCA\Portaliq\Event\PortalContactDetailsChangedEvent`, which carries:

| Field | Meaning |
| --- | --- |
| `getSubjectRef()` | the person, as your case app scopes by it |
| `getOrganisation()` | the organisation the account belongs to |
| `getChannel()` | `portal`, `email`, `phone` or `post` |
| `hasPreferredEmail()` | whether a preferred, confirmed e-mail address exists |
| `hasPreferredPhone()` | whether a preferred phone number exists |

A case app that writes letters or phones listens for this event and honours the choice. Saving the same channel again raises nothing.

## The prompt for a missing address

After sign-in the portal asks "Add an e-mail address so we can tell you when something changes." when the account has no address in use, or when notification dispatch marked the account as needing another way to reach the person. The person can go to **My account** or dismiss the prompt for the rest of the session.

## Removing the account

**Remove my account** asks for confirmation first and says what happens: "Your portal account is removed. Your cases stay with the organisation." After confirming, the person is signed out. The account keeps no name, address, phone number, identity or link to an app; the cases are not touched.
