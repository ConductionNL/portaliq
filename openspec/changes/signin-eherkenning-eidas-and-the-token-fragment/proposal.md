---
kind: code
---

# Proposal: signin-eherkenning-eidas-and-the-token-fragment

## Why

The sign-in spec says that a business user signs in with eHerkenning and an EU visitor with eIDAS, but only in its purpose line. No requirement says what each of those sign-ins makes of the person. The code already decides it: `OidcClaimMapperService::PRESETS` gives eHerkenning the identity type `eherkenning` and the audience `supplier`, and eIDAS the identity type `eidas` and the audience `client`. The broker route takes the same preset audience (`BrokerLogin::redeem`). Nothing pins that, so a change to a preset would pass every spec.

The same holds for the bearer that a sign-in hands to the browser. Every sign-in route ends with a redirect carrying `#token=` (`SessionController`, `BrokerSessionController`, `ExampleResidentController`), and both frontends read it and strip it from the address (`src/site/lib/authApi.js` `adoptSessionToken`, `src/shared/portalApi.js` `consumeOidcCallbackFragment`). Only the Nextcloud way in has a requirement for this (REQ-NCL-004), and none says the token must leave the address bar.

## What changes

- **An eHerkenning sign-in is a business sign-in.** The session carries identity type `eherkenning` and audience `supplier`, on the OIDC route and on the broker route alike, with the branch claim when the organisation names one.
- **An eIDAS sign-in is a visitor from another EU country.** The session carries identity type `eidas` and audience `client`. Its subject reference is derived by portaliq, never taken from a citizen service number.
- **The token travels in the fragment and leaves the address.** Every sign-in route hands the bearer over in `#token=`. The site and the shared portal client read it once, keep it, and replace the address without it.

## Rows covered

- `sig-eherkenning` (building), `cmp-sig-eidas` (building), `sig-token-fragment` (built). Boards: Inloggen, PtPortalInstellingen.

## Out of scope

- The vendor SAML service provider or OIDC relying party behind integriq's government IdP adapter. That half is integriq's (`integriq/portal-idp-broker-config`).
- Choosing a branch after sign-in (REQ-SRP-011, signin-eherkenning-branch).
