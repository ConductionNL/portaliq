---
kind: code
---

# Proposal: zuiddrecht-site-details

## Why

The live test of the Zuiddrecht demo on 13 and 14 October 2026 found three loose ends on the example site:

1. The portal has no `organisation`. The coordinator set it by hand on the test instance. Without it, a resident's first portal account (REQ-NCL-003) is refused ("a portal without an organisation"), and the portal accounts the example set writes in organisation `zuiddrecht` belong to no portal.
2. The footer, the sign-in notice and two content blocks still read `[telefoonnummer]`, `[e-mailadres]` and `[adres]`.
3. The card "Bewaren of volgen" on a publication says "Log in om deze publicatie in uw eigen dossier te bewaren." also to a visitor who is signed in.

## What changes

- **The example site names its organisation**: `organisation: zuiddrecht`, the slug of the OpenRegister organisation the Zuiddrecht example set creates. Portaliq keys a tenant by organisation slug (`PortalOrganisationConfigService::findOrganisationBySlug()`, `BrokerSessionController`), and the set's portal accounts and mandates carry `zuiddrecht`. The loader's `{{organisation:zuiddrecht}}` would write the organisation's uuid, which matches none of them, so the site uses the slug directly and needs no placeholder.
- **Made-up contact details**: `14 0999`, marked as an example number in the footer, `info@zuiddrecht.example` on a domain reserved for examples, and Stadskantoor, Lindelaan 1 (the address the site already gives for the desk). "Is er direct gevaar?" names 112: a visitor in danger must never read a fake number.
- **The sign-in card does not invite a signed-in visitor to sign in.** `nlSignIn` gets `signedInIntro`. Signed in, the card shows that text, or no text when the page wrote none. The button already changed to the resident's own area.

## Screens

- `screens-src/zuiddrecht/Home.dc.html` and the content boards: the footer's contact lines.
- The publication page's "Bewaren of volgen" card.

## Out of scope

- An instance that already installed the site keeps its portal row: the installer writes only what its record lacks. Set `organisation` there by hand, or remove and install the site again.
