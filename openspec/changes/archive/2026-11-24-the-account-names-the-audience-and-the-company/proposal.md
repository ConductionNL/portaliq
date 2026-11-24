## Why

The school portal plan has company users: an employer at a training academy signs in with eHerkenning
and arranges courses for her employees (board warmtepompacademie, "U regelt het voor, Jansen
Installatietechniek BV"). Two things in portaliq stood in the way:

- **The session ignored the account's audience.** An app (learniq) invites the employer and portaliq
  provisions her account with audience `employer` and the claim her collections are scoped by. When
  she signs in, both sign-in routes found that account and then minted the session with the audience
  the route proposed: the provider preset over the broker route (`supplier` for eHerkenning,
  `BrokerLogin`), the organisation's claim map over the OIDC route. A `supplier` session is answered
  by no `employer` provider, and the scope claim is looked up by subject AND session audience, so she
  saw an empty portal. The OIDC route can be bent per organisation, the broker route cannot.
- **The session named no company.** The site already reads `session.organisationName` (the person
  chip and the menu card of `resident-menu-badges-and-cards`), but nothing set it.

## What Changes

- `PortalAccountService::findOrCreate()` returns the audience the session must carry: an existing
  account's own stored audience, else the proposed one (a new account). Both sign-in routes mint the
  session (audience and `<audience>:read` role) with it. An account's audience is written only by
  provisioning on the server, never by the person, so this widens nothing: it makes the session agree
  with the account it was minted for.
- The session answer (`GET /api/session`) carries `organisationName`: the first non-empty
  `claims.<appId>.organisationName` on the person's account, trimmed, at most 200 characters, else ''.
  An app that invites a person for a company writes that claim next to its scope claim, through the
  existing claim event. The portal's own organisation slug (a tenant) is never used for it.

## Not in this change

- A way for a provider to add other session lines.
- Branch or KvK details in the chip.
