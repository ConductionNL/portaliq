# Design: identity-registered-details

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `portalAccount` (`lib/Settings/portaliq_register.json`, schema
  `portalAccount`) carries `identityType` (`digid`, `eherkenning`, `eidas`,
  `generic`, `dev`), `identityRef` ("Pseudonymous identity reference from the
  IdP (e.g. eHerkenning KvK number for suppliers, or a pairwise pseudonym)"),
  `displayName` and `email`. Nothing BRP or KvK shaped.
- `lib/Service/OidcClaimMapperService.php:209` fills `identityRef` from the
  claim the portal configures (`identityRefClaim`, default `sub`). Whether that
  is a BSN depends on the broker.
- The signed-in SPA shell is `src/portal/App.jsx`. `buildNav()` (lines 48-76)
  builds the nav from contribution pages plus two fixed entries, "My tasks"
  (`special: 'tasks'`) and "Inbox" (`special: 'inbox'`). This change adds a
  third fixed entry. Page components live in `src/portal/components/`.
- The portal API adapter is `src/portal/lib/portalApi.js`; bearer-guarded
  routes live under `/portal/api/*` in `appinfo/routes.php` (identity routes at
  lines 269-282).

## D1. One read endpoint, subject from the bearer

`GET /portal/api/identity/registered-details`, `#[PublicPage]` with the bearer
resolved through `PortalSessionService` like
`PortalAccountSelfController::updateDetails()` (`lib/Controller/PortalAccountSelfController.php:92`).
The client sends no identifier. The controller reads the caller's own
`portalAccount` and decides what to look up:

- `identityType` `digid` or `eidas` with an `identityRef` that passes
  OpenRegister's `BsnFormat` check: a person lookup.
- `identityType` `eherkenning` with an 8-digit `identityRef`: a company lookup.
- Anything else: `{ available: false, reason: 'no_registration_identifier' }`.

A new `lib/Service/Identity/PortalRegisteredDetailsService.php` holds that
decision and the two calls, so the controller stays thin.

## D2. Read through OpenRegister, in process

Portaliq already uses OpenRegister in process (`ObjectService` through
`PortalObjectReader`). The service resolves openregister's
`BrpPersoonProvider` and `KvkProvider` from the server container when
`class_exists()` finds them, the same guard portaliq uses for every optional
OpenRegister class. It never holds a URL, a token or a certificate: those live
on the OpenConnector sources `brp-haalcentraal` and `kvk`.

A provider that answers `{ unavailable, cause }` becomes
`{ available: false, reason: 'source_unavailable' }`. The resident reads
"Your registered details cannot be shown right now." The cause goes to the log
without the BSN.

## D3. Map in portaliq, show little

The provider returns the raw HaalCentraal person object. Portaliq maps it to a
small, fixed shape: `name`, `birthDate`, `address` (street, number, postcode,
city) and, when the count query exists, `residentsAtAddress`. For a company:
`tradeName`, `kvkNumber`, `legalForm`, `branches[]` (number, name, address).
The full object never reaches the browser, and the BSN is never echoed back.

## D4. Two request links, bound by the administrator

`portal` gains an optional `registeredDetails` object with two properties:
`correctionFormBinding` and `addressInvestigationFormBinding`, each the id of a
`portalFormBinding` on the same portal. The section renders a link to the
binding's `route` only when it is set and published. Unset means no link, not
a link that fails. The request is an ordinary intake submission, so the case
app that owns the binding's case type receives it.

## D5. The count waits for openregister

`residentsAtAddress` is filled only when openregister exposes a count by
address object. Until then the service leaves it out and the section shows
"The number of residents at this address is not available." No second BRP
client is built here.

## Risks

- A BSN on `portalAccount.identityRef` is personal data at rest. The lookup
  depends on it being there, and ADR-064 requires it stored through
  OpenRegister's format. The service only reads it.
- A broker that sends a pseudonym makes this section empty for every resident.
  That is correct, and the empty state says why.

## What it deliberately does not do

- It writes nothing to `portalAccount`.
- It adds no admin screen: the two bindings are set on the portal record.
