---
kind: code
---

# Proposal: demo-sign-in-beside-the-account

## Why

The live test of the Zuiddrecht demo on 13 and 14 October 2026 found three faults on the sign-in page, all from one decision: the example set relabelled the `nextcloud` way in as the "Voorbeeldinwoner" card.

1. With the demo switch on, that card linked to the one-click route, so "Account aanmaken" and "Wachtwoord vergeten" were dropped ("never on the demo") and nobody could sign in with an account of their own, also not a resident who had just registered.
2. With the demo switch off, the site left the whole `nextcloud` way out because the set had added it, so the registration that portaliq #1546 built led to an account with no way in.
3. Only Sanne had a card. Linda, who acts for her father Henk with a mandate, could not be shown at all.

And the example residents were in no group, so for the registration and landing logic (`authentication.registration.residentGroup`) they were not residents.

## What changes

- **The `nextcloud` card is the account card of the Inloggen board**: "Met een account", "Voor wie publicaties volgt, dossiers bewaart of een vraag stelt. Geen DigiD nodig.", with "Wachtwoord vergeten" and "Account aanmaken". It stays the account route on a demo too.
- **One one-click card per example resident, beside the portal's own ways in.** `exampleResident` in a fragment may be a list. Each entry names the portal, the cast key, the portal account object and the card. The trust rule is unchanged: a resident counts only when OpenRegister's install record holds its portal account and lists its account id as created or adopted. The route takes `resident=<cast key>`; without one it signs in the first resident, so an old link still works.
- **The Zuiddrecht fragment offers Sanne, Henk, Linda and Fatima**, and declares the group `inwoners` with those four as members. The portal names it as `authentication.registration.residentGroup`, in the same change that adds the `nextcloud` way in.
- **The portal schema declares `authentication.registration.residentGroup` and `residentAudience`**, which portaliq already read but the schema did not name.
- **`exampleResidentWayIn` retires.** With the account card back, the way in the set adds is a real one, and hiding it while the switch is off is what made registration a dead end.

## Decisions taken

- **Business contacts stay out of `inwoners` and get no one-click card.** Jan-Willem and Mark act for a company. In reality that is eHerkenning, and their portal accounts already have audience `supplier`. The group is the portal's statement of who its residents are: the registration app fills it, and a member's first account gets audience `citizen`. They keep their accounts and sign in through the account card. No separate group is made, because nothing reads one.
- **The group lives in portaliq's fragment, not in OpenRegister's cast.** The group belongs to the deployment and the portal names it. Only portaliq reads it. The loader keeps a group that already exists, such as the one an administrator made for the registration app, and does not delete it on remove.
- **The change key is new (`inloggen-met-account`)**, so an instance that installed the old card gets the account card on the next install. The old change stays recorded, and remove still puts back exactly what it set.

## Screens

- `screens-src/zuiddrecht/Inloggen.dc.html`: the "Met een account" card with "Account aanmaken" and "Wachtwoord vergeten".
- `screens-src/zuiddrecht/AccountAanmaken.dc.html`: unchanged, the registration app's form.
- The one-click cards are not on any board. They are demo-only and say so under their button.

## Out of scope

- The landing of a new resident after registration: `resident-lands-on-the-portal`.
- One-click cards for business contacts.
