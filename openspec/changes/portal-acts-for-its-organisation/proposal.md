# Proposal: portal-acts-for-its-organisation

## Why

Portaliq reads and writes for portal residents, who are not Nextcloud users, so it reads as the system and keeps tenants apart with its own per-row organisation check. OpenRegister can now act for an organisation without a user (`ObjectService::runForOrganisation()`, openregister change `act-for-an-organisation`): inside it the organisation filter stays on whatever the caller passes, and creates get that organisation. Ruben decided on 3 November 2026 that portal requests run inside that context, so OpenRegister is the boundary and the app checks become a second line.

## What changes

- A portal read or write for a subject runs as the portal's organisation in OpenRegister. The portal names its organisation by slug; the slug maps to the organisation uuid through `PortalOrganisationConfigService::findOrganisationBySlug()`.
- A slug that names no organisation reads and writes nothing.
- Claim-addressed change notices list the accounts of the changed record's organisation only.
- Anonymous reads and anonymous submissions stay as they are.
- `occ portaliq:tenancy:restamp` gives existing rows the organisation their data names. It reports by default and writes only with `--apply`.

## Rollout

Rows portaliq wrote as the system carry the default organisation in `@self.organisation`. On an instance with more than one organisation, a portal acting for its organisation does not see those rows until they are restamped. Run the dry run, read the rows it cannot resolve, then run `--apply`, before or right after upgrading.

## Not in this change

- The per-row organisation checks stay. Removing them is a later change, once every instance is restamped.
- Portal-scoped schemas (`portal` field) and rows in other apps' registers are not restamped.
- Account reads outside the reader (sign-in, `PortalAccountService`) still read as the system.
