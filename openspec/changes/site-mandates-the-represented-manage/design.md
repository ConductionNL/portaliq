# Design: site-mandates-the-represented-manage

## D1. Who is the represented party

The session decides, never a parameter:

| Session | Party it may manage |
|---|---|
| eHerkenning (business, supplier) | `kvk:<the session's KVK number>` |
| DigiD (citizen, client) | `subject:<the session's subjectRef>` |

A session acting under a mandate manages nothing on the represented party's behalf. Managing mandates is not something a mandate grants.

## D2. Typed `onBehalfOf`

- Grammar: `kvk:` plus exactly 8 digits, or `subject:` plus a non-empty reference.
- `PortalMandateService` normalises on read: an untyped value of 8 digits reads as `kvk:<value>`; any other untyped value reads as `subject:<value>`. Existing access-request mandates keep working without a migration.
- Every new write, including `PortalAccessRequestService::grant()`, writes the typed form.
- A repair step may rewrite old rows later; it is not needed for correctness.

## D3. Inviting

`POST` an invitation with email, scope (`caseTypes`, empty for all), `label` (a short text the inviter writes, e.g. "Mag alleen bezwaren indienen en volgen") and an optional `expiresAt` (a future date).

- `PortalInvitationService::invite()` creates the invitation, as for guardian invitations, and stores the mandate terms on it: `onBehalfOf` from D1, `caseTypes`, `label`, `expiresAt`.
- Accepting (`accept()`, signed in) writes the `portalMandate` for the accepting account's `subjectRef`, with `grantedBy` the inviter and `invitationId` set. One accept, one mandate. A second accept of the same token is refused, as today.
- The inviter cannot accept their own invitation.
- No BSN, name lookup or KVK lookup of the invitee takes place.

## D4. Ending

- The represented party: "Intrekken" sets `status: revoked`, `revokedBy`, `revokedAt`. "Uitnodiging intrekken" calls `PortalInvitationService::revoke()`.
- The grantee: "Machtiging stoppen" revokes their own mandate the same way.
- An end date can be set or moved by the represented party, never to the past. `mandatesFor()` already drops an expired mandate.
- A revoked mandate takes effect on the next request: `mandatesFor()` reads it fresh, and an acting-for choice under it falls back to acting as yourself.

## D5. Routes and checks

Portal-session routes, all under the portal auth edge:

- `GET  /portal/api/mandates/given`: mandates and open invitations where `onBehalfOf` is the session's party (D1).
- `POST /portal/api/mandates/invitations`, `DELETE /portal/api/mandates/invitations/{id}`
- `POST /portal/api/mandates/{id}/revoke`, `PUT /portal/api/mandates/{id}/expiry`
- `GET  /portal/api/mandates/held`, `POST /portal/api/mandates/held/{id}/stop`

Every route reads the party from the session and compares it with the row. A row of another party answers 404, the same as a missing one. A session acting under a mandate gets 403 on the "given" routes.

## D6. Screens

- "Machtigingen" in the resident menu's account group, for a business session and for a person who has given at least one mandate or invitation. Each row: initials, name or email, label, "Geldig tot en met …", state badge ("Actief", "Wacht op antwoord"), and the end action.
- "Uw machtiging" on the grantee's account page and under the acting-for bar: label, end date, given by, given on, "Machtiging stoppen".
- The grantee's display name comes from the accepted account. An invitation shows its email address until it is accepted.

## Decided

- **A private person may give a mandate** (Ruben, 3 October 2026). It works by email invitation, as for a company: the person signs in once with DigiD to send it, and the invitee accepts after their own sign-in. The represented party is then `subject:<the person's subjectRef>` (D1).

## Open

- **Who holds a mandate**: the one person who accepted (this design), or every session of the company that accepted. Not decided. Until it is, build the one-person holder only.
