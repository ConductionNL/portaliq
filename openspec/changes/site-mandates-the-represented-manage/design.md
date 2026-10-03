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
- Accepting (`accept()`, signed in) writes the `portalMandate` with its holder from D7, `grantedBy` the inviter and `invitationId` set. One accept, one mandate. A second accept of the same token is refused, as today.
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

## D7. Who holds a mandate (decided)

Ruben decided on 3 October 2026: portaliq keeps its own mandate record, and a company holds a mandate it accepts.

| Accepting session | Holder written on the mandate | Who carries it |
|---|---|---|
| eHerkenning, for company X | `kvk:<X's 8 digits>` | every eHerkenning sign-in for KVK X |
| DigiD (e.g. Linda Bakker) | `subject:<Linda's subjectRef>` | Linda only |

This is a change. Today a mandate is held by one account: `portalMandate.subjectRef`, written by `PortalAccessRequestService::grant()`, and read by `PortalMandateService::mandatesFor(subjectRef)`, which matches the session's own `subjectRef` only. What moves:

- **The record**: `portalMandate` gains `holder` (typed like `onBehalfOf`). `subjectRef` stays for existing rows and reads as `holder: subject:<subjectRef>` when `holder` is empty.
- **The accept path**: `PortalInvitationService::accept()` writes `holder` from the accepting session: `kvk:` for an eHerkenning session with a KVK number (see D8), else `subject:`.
- **The reader**: `PortalMandateService::mandatesFor()` takes the session's parties (`subject:<subjectRef>`, and `kvk:<n>` for an eHerkenning session) and keeps mandates whose holder is one of them. Its callers (`MyCasesController`, `CitizenCaseController`, `MandatedCaseReader`) pass the session, not only the `subjectRef`.
- **Audit keeps the individual**: `CitizenWriteRecorder::identity()` records the acting session's `subjectRef` and `mandate()` the party and mandate, so the case timeline reads "{name}, namens {party}" with the person who acted, even when the company holds the mandate. That holds only while each person has their own account, which is what T0 settles (D8).

## D8. Does an eHerkenning session know its KVK number? (verified, and blocking)

Read on `development` (4cdfb532):

- `OidcClaimMapperService` maps one identity claim, `claimMap.identityRef`, default `sub`, for every provider. The eHerkenning preset names no KVK claim. Only `claimMap.branch` (the vestigingsnummer) is eHerkenning-specific.
- `PortalRegisteredDetailsService::kindOf()` and `BranchChoice` treat an eHerkenning account's `identityRef` as the KVK number, but only when it happens to be 8 digits. Whether it is depends on what the broker puts in the claim the organisation mapped.
- A portal account is keyed by `(identityType, identityRef, organisation)` (`PortalAccountLookup::byIdentity`). If `identityRef` is the KVK number, every employee of the company shares one account, and the timeline cannot name the person who acted. If it is a person's pseudonym, the session does not know the company's KVK number at all.

So the KVK number of the company a person signs in for is not reliably on the session. Task T0 resolves it before anything in D7 is built: a separate `claimMap.kvk` claim beside a per-person `identityRef`, carried on the session as `kvk`.

## Why not OCM

OpenRegister's Open Cloud Mesh support shares between two Nextcloud servers, and both parties need an instance and a cloud id. Portal visitors sign in with DigiD or eHerkenning and have neither. Its `FederatedShare` has no expiry and no case-type scope, which `portalMandate` already has.

Delegation between two instances (OpenRegister change `platform-cloud-federation-provider`) may extend this later. It is not part of this change.

## Decided

- **A private person may give a mandate** (Ruben, 3 October 2026). It works by email invitation, as for a company: the person signs in once with DigiD to send it, and the invitee accepts after their own sign-in. The represented party is then `subject:<the person's subjectRef>` (D1).

- **A company holds a mandate it accepts; a person holds their own** (Ruben, 3 October 2026), in portaliq's own record, not through OCM (D7).

## Open

- How the KVK number reaches the session (D8, T0).
