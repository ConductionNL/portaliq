---
kind: code
depends_on: [portal-status-transitions, portal-take-assessment]
---

# Proposal: case-actions-sign-a-document

## Why

A resident who has to sign an agreement or a mandate form today downloads it,
prints it, signs it and sends it back. The organisation prepared the document
in filinq, and filinq can already take a signature from someone without a
Nextcloud account. Nothing in the portal lets the resident give it.

The demand row, portaliq matrix, row `dem-tnd-sign-in-portal`, origin
`tender`, <https://www.tenderned.nl/aankondigingen/overzicht/409958>. The
matrix `originNote`, verbatim:

> TenderNed 409958 GR Sociaal (schuldhulpverlening): 'Client kan documenten ... digitaal ondertekenen in het inwonerportaal via DigiD, of ValidSign'

No competitor in the portaliq matrix is rated `yes` on this row. The one
`partial` cell, `liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/digital-asset-management/uploading-and-managing/enabling-docusign-digital-signatures 'integrate DocuSign digital signatures into your Liferay documents ... manage and collect signatures'. Signing runs through a DocuSign envelope, a separate paid service, not a signing step inside a portal page. [was unknown]

The `xxllnc-pip` cell, rated `no`, verbatim:

> signing is staff-triggered through an external app backend/perl-api/lib/Zaaksysteem/Backend/Sysin/Modules/KoppelAppSigner.pm:59 and ValidSign.pm; PIP documents have no sign action [reached on nothing; was unknown from docs]

The row's `built` cell, verbatim:

> grep -riE 'signature|onderteken|handteken' src/portal src/site: 0 hits; lib hits for 'sign' are JWT/HMAC signing only (SessionController, PortalJwtService, traffic token)

The lane recorded this row as `build` on the tender rule.

## What changes

Filinq already offers the signing collection and the sign and decline acts
to the portal. Portaliq drops every one of them on the way to the screen.
This change builds the portaliq half that makes them reachable:

- **A row action that forwards to an endpoint.** A collection's `rowActions`
  may name an endpoint action of the same contribution, not only a
  `type: update` action. The portal renders it as a button on each row.
- **A row-scoped forward.** A new route forwards an endpoint action for one
  row the resident can read. Portaliq proves the row is in the resident's
  own scoped collection first, and stamps the row id into the forwarded body
  under a field the action declares. The browser never names the target.
- **The signer's scope travels inside the signed assertion.** When the
  forwarded action declares a `scopeClaim`, the `X-Portal-Subject` assertion
  carries the claim value portaliq resolved server-side. This is option (a)
  that filinq's `portal-signing-actions` names as its apply-blocker.
- **A signing screen.** Before the resident signs, the portal fetches the
  document through the contribution's `viewDocument` action for that row,
  shows it, says what signing means, and asks for a confirmation. A decline
  asks for a reason. The result of the act is shown, not discarded.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-sign-in-portal` | Sign a document the organisation prepared for you, such as an agreement or a mandate form, inside the portal. | no | A portal screen that shows the document and forwards the sign or decline act to filinq's rail with the resident's server-derived identity. |

## Existing work it builds on

- `portal-contribution-contract` (spec): the A6 endpoint forward
  (`POST /portal/api/actions/{appId}/{actionId}`), the frozen assertion wire
  format and `scopeClaim` scoping. This change modifies the assertion format
  and the `rowActions` vocabulary.
- `portal-status-transitions` (open): introduced `rowActions` as ids of
  `type: update` actions. This change widens it to endpoint actions.
- `portal-take-assessment` (open): introduced `subjectField`, a body field
  the server stamps with the resolved scope. This change keeps that and adds
  the row stamp beside it.
- `2026-09-07-contract-v2` (archived): the forwarder and the assertion.
- `2026-09-07-portal-scoped-crud` (archived): the scoped single-object read
  the row-scoped forward reuses to prove the row is the resident's.

## Out of scope

- Producing the signature. Filinq's `SigningService` owns the act, the
  evidence and the assurance level.
- Qualified electronic signatures. Filinq's `portal-signing-surface` states
  the surface claims SES or AES only, and this change claims nothing more.
- Choosing who must sign. The organisation invites the signer in filinq.
- A signing step inside a case form. This change signs a document filinq
  holds; a form that ends in a signature is a separate question.

## Sibling halves

- **ConductionNL/filinq** owes two things, neither written here:
  - Serve the signing collection to the `client` audience. Its
    `PortalContributionProvider::getAudiences()` returns
    `['data-subject', 'signer']`, and a DigiD session in portaliq carries
    audience `client` (`lib/Service/OidcClaimMapperService.php:74-80`). A
    resident who signs in with DigiD is never offered filinq's collection
    today.
  - Scope that collection by a claim the organisation sets when it prepares
    the document, never by an email address the resident typed into their
    own profile. Portaliq forwards whatever claim the action declares; which
    claim proves the resident is the invited signer is filinq's call.
- Filinq's open changes that carry its side: `portal-signing-actions`,
  `portal-signing-surface`, `one-signing-rail-for-the-fleet`,
  `signer-identity-rails` and `libresign-signing-provider`.
