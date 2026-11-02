# Design: portal-domains-and-editors

## Screens

| Board element (PtPortalInstellingen, PtPortal) | Source |
|---|---|
| Card "Domeinen": domain, status pill, certificate ("geldig tot 14 jan 2027" or "nog geen"), TXT instruction on a pending row, "Opnieuw controleren", "Domein toevoegen", the line "Een domein werkt pas als het geverifieerd is" | `portal.domains[]`, `GET /api/cms/domains`, `POST /api/cms/domains/verify` |
| Side card "Domein en certificaat" | first verified domain and its `certificateValidUntil` |
| Side card "Beheerders": initials, name, role | `portal.editors[]` |
| Card "Gegevens van de portal" | the portal's own fields |

## Data

`portal.domains[]` items gain `certificateValidUntil` (date-time) and `certificateCheckedAt` (date-time). `portal` gains `editors`: a list of `{userId, displayName, role}`, `role` one of `chief-editor`, `editor`. Portal schema minor version up. All new fields are optional, so an existing portal reads as before.

## Verification

Unchanged: the tenant publishes the domain's token as TXT `_portaliq-verify.<hostname>`; `PortalDomainVerifier::verify` looks it up and sets `verified` and `verifiedAt`. A domain without the record stays `pending` and does not serve.

## Certificate

After a verification run, for a domain that is verified, the verifier opens a TLS connection to `<hostname>:443` with a 5 second timeout, reads the peer certificate and stores its `validTo` as `certificateValidUntil`, with `certificateCheckedAt`. A host that does not answer, or answers without a certificate, keeps the previous value and only updates `certificateCheckedAt`. The probe does not verify the chain: it reads the end date and nothing else, and does not send a request. It is injectable so tests never open a socket.

## Adding a domain

The card writes the new `{hostname, verified: false}` into the portal object. The verifier mints the token on the next `records` call, as today. A hostname must be a plain host name without scheme, port or path.

## Editors

The card writes `portal.editors` into the portal object. A `userId` is a Nextcloud user id; `displayName` is stored beside it so the list reads without a lookup per person. A user listed here gains no right (see the proposal).
