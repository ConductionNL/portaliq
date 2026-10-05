# Proposal: invitation-secret-joins-the-signed-in-account

## Why

A school invites a guardian through learniq. Learniq asks Portaliq for a waiting account on the guardian's address and writes the `learniq.guardianRef` claim on it. The guardian reaches that account only when her sign-in carries the same verified address (REQ-PIS-002, REQ-PIS-005) or when she confirms that address in the portal (REQ-PIS-006).

A sign-in through the integriq broker carries no address. The DigiD answer holds a pseudonym, the provider and the trust level. A guardian who uses another address than the school has on file is never matched at all. And no mail is sent today, so she does not even know she was invited.

## What changes

- A waiting account can carry a one-time secret. Portaliq stores only its hash, with an expiry of seven days, the same week an invitation into the portal has.
- An app asks for the invitation with a typed event, `PortalAccountInvitationRequestedEvent`. Portaliq mints the secret and mails it to the waiting account's own address, inside a link. The secret is never answered to the app, so staff never see it.
- The link opens the portal's site with the secret in the fragment. The site strips it from the address, keeps it in the browser tab through the sign-in, and hands it back once the person is signed in. This works for every sign-in route, the broker included.
- A new route, `POST /portal/api/identity/invitation/redeem`, takes the secret from a signed-in session at trust level substantial or higher. The waiting account behind the secret joins the session's own account: its claims are added, and it is withdrawn with a reason. This is the join of REQ-PIS-005, with the waiting account found by the secret.
- Wrong, expired and already used secrets get one and the same answer. Five wrong secrets lock the route for the account for an hour, and for the session for good.
- Each join is written to the audit trail: which account took over which waiting account, in which session, and when.

## Trust

The secret is the proof. It was mailed to the address the school verified with the guardian, so holding it shows what a verified address shows in REQ-PIS-002. The session adds who she is: a sign-in at substantial or higher. The waiting account must still be pending, without an identity reference, and in the session's organisation.

Only the app that provisioned a waiting account may ask for its invitation.

## Not changed

- integriq and what the broker returns. No BSN is read or stored.
- The invitation staff send from Portaliq itself (`portalInvitation`, REQ-ISA-001). It creates a waiting account; this change is about reaching one.
- The sign-in. A person who never follows the link signs in as before.

## Follow-up

A short code for paper letters, typed into the portal, uses the same route and the same join. It is a separate change.
