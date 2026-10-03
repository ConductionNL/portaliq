# Proposal: site-mandates-the-represented-manage

## Why

Two approved mockups show the person or company being represented managing who may act for them. `DossiqBusiness.dc.html` lists "Wie mag zaken regelen voor uw bedrijf?" with each person's scope and end date, "Iemand machtigen", "Intrekken" and "Uitnodiging intrekken". `DossiqPhone.dc.html` shows Linda Bakker's "Uw machtiging" (scope, valid until, given by) with "Machtiging stoppen". The dossiq lane's `site-business-and-authorisation` (dossiq PR #3249) depends on this change by name.

What portaliq has on `development` (b150def5):

- **The record.** `portalMandate` holds `subjectRef` (who may act), `organisation`, `onBehalfOf` (for whom), `label`, `caseTypes`, `reach` (`organisation` or `tree`), `status` (`active` or `revoked`), `grantedBy`, `grantedAt` and `expiresAt`.
- **The reader.** `PortalMandateService::mandatesFor()` returns only active, unexpired mandates of one identity. `ActingForSwitcher.vue` offers them, and "Mijn zaken" reads the represented party's cases through them (REQ-CMC-003, REQ-CMC-004).
- **The one writer.** `PortalAccessRequestService::grant()` writes a mandate when staff grant an access request (REQ-IAR-003). It writes `reach: organisation`, no `expiresAt`, and `onBehalfOf` exactly as the asker typed it, for example `87654321`.
- **Invitations.** `PortalInvitationService` sends a token by email, opens it, accepts it for the signed-in account and can revoke it (`portalInvitation`: `email`, `state`, `tokenHash`, `expiresAt`, `subjectRef`).
- **Branches.** An eHerkenning session carries its branch (REQ-SEB-001 to 003).

What is missing: nobody but staff can create a mandate. The represented party cannot see, add, limit or end the mandates given on its behalf. A grantee cannot stop one. `onBehalfOf` has no type, so a KVK number and a person's reference look alike.

## The limit, stated plainly

Portaliq holds no BSN. A person is known only by `subjectRef`, a one-way reference derived at sign-in. So a person who never signed in to the portal cannot be named. "Iemand machtigen" therefore never asks for a BSN or a name to look up. It sends an invitation by email. The mandate exists only once the invitee signs in and accepts, and it is then held by the account that accepted. Until then the list shows "Wacht op antwoord", as `DossiqBusiness.dc.html` does for Tom Visser.

A company is different: its KVK number is public and is what eHerkenning returns. A company can be the party represented (`kvk:<number>`). As the receiving side it is still reached through a person who accepts.

## What changes

- `onBehalfOf` gets a typed form: `kvk:<8 digits>` or `subject:<subjectRef>`. Writes use it. Reads accept an untyped 8-digit value as `kvk:` so the access-request mandates keep working.
- The represented party gets a page, "Machtigingen", listing every mandate and open invitation given on its behalf: who, what they may do, until when, and the state.
- "Iemand machtigen": email address, scope (all cases, or chosen case types), an end date. It sends an invitation. Accepting writes the mandate.
- "Intrekken" ends a mandate at once; "Uitnodiging intrekken" revokes an open invitation; an end date can be set or changed.
- The grantee sees "Uw machtiging" and can stop it ("Machtiging stoppen").
- Every write records who did it and when.

## Decided

- A private person may give a mandate, by email invitation: they sign in once with DigiD to send it, and the invitee accepts after their own sign-in (Ruben, 3 October 2026).

## Not in this change

- Looking up a person by BSN or name. Impossible by design, see above.
- Mandates through an external register (eHerkenning ketenmachtigingen, DigiD Machtigen). Portaliq reads only its own records.
- A mandate held by a whole company rather than by the person who accepted. Open, not decided (design, "Open").
- The case filtering on `portalParty`: dossiq's change, over `mandateField` (REQ-CMC-003).

## Affected projects

- portaliq: `lib/Settings/portaliq_register.json` (`portalMandate` gains `revokedBy`, `revokedAt`, `invitationId`; `portalInvitation` gains `mandate` terms), `lib/Service/Identity/PortalMandateService.php`, a new `PortalMandateAdminService` and controller, `PortalInvitationService` (accept writes the mandate), `PortalAccessRequestService` (typed `onBehalfOf`), the site pages and `ActingForSwitcher.vue`.
- dossiq: none here; it reads `onBehalfOf` through `mandateField`.
