---
kind: code
---

# Proposal: business-audience-group

## Why

The Zuiddrecht example set has three business contacts: Jan-Willem van der Berg, Mark Visser and Karin Smit. The example-resident spec kept them out of `inwoners` on purpose, because that group is the portal's statement of who its residents are and a member's first account gets audience `citizen`. No other group existed, because nothing read one.

So they were in no group the portal names. A business contact who signs in to Nextcloud directly landed in the workplace instead of on the portal, was not sent back by portaliq's app page, and Karin, who has no seeded portal account, could not get one on her first sign-in.

Decision of 16 October 2026: business contacts get their own portal audience group, `ondernemers`, which portaliq reads the same way it reads the resident group.

## What changes

- **A portal may name a business group**: `authentication.registration.businessGroup`, with `businessAudience` (default `supplier`, the audience the set's business accounts already carry). It means something only on a portal that offers the `nextcloud` way in, like `residentGroup`. The group name is configuration: no code names `ondernemers`.
- **Everywhere portaliq treats a resident group member as a portal user, it treats a business group member the same way, with the business audience**: the first portal account on sign-in through the account route (REQ-NCL-003), the way back from the workplace (REQ-NCL-006) and the start page on joining the group (REQ-NCL-007).
- **The registration app never fills the business group.** "Create an account" stays tied to the resident group only (REQ-NCL-005): a registration app set to the business group opens no link.
- **A group named as both** counts once, as the resident group with the resident audience.
- **The portal schema declares `businessGroup` and `businessAudience`.** The register moves 0.97.0 to 0.98.0 and the portal schema 0.25.0 to 0.26.0.
- **The Zuiddrecht fragment declares the group `ondernemers`** with Jan-Willem, Mark and Karin, and a new change `ondernemers-op-het-portaal` sets `authentication.registration.businessGroup` to `ondernemers` on the portal. The OpenRegister loader creates the group when the instance lacks it and deletes it on remove; a group that already existed is kept. A new change key means an instance that installed the set before gets the business group on the next install.

## Decisions taken

- **Two named keys, not a list of audience groups.** They mirror `residentGroup` and `residentAudience`, which the schema, the registration door and the set already use. A list would be a second shape for the same declaration.
- **Default business audience `supplier`.** Jan-Willem's and Mark's seeded accounts have audience `supplier`, and the schema's audience vocabulary names it. A portal that wants another word sets `businessAudience`.

## Out of scope

- One-click demo cards for business contacts.
- eHerkenning as the way in for business contacts.
- The platform's demo reset (`limitApps.exceptGroups`) lives in Nextcloud-base, not here.
