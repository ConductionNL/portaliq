# Proposal: confirmed-address-joins-the-waiting-account

## Why

A school invites a guardian through learniq. Learniq asks Portaliq for a pending account on the guardian's verified address and writes the `learniq.guardianRef` claim on it. The guardian reaches that account when her sign-in carries the same verified address (REQ-PIS-002, REQ-PIS-005).

A sign-in through the integriq broker carries no address: the DigiD answer holds a pseudonym, the provider and the trust level, and nothing else. The guardian gets a new, empty account. The invited account stays pending and she sees no child.

The portal then asks her for an e-mail address, and she confirms it through a mailed link. That confirmation proves she holds the address. Today nothing is done with that proof.

## What changes

- When a person follows the confirmation link for an address, Portaliq looks for a pending, email-only account with that verified address in the same organisation.
- When it finds one, and the link was opened in the account holder's own session at trust `substantial`, the claims of the pending account are added to the account that confirmed the address, and the pending account is withdrawn with a reason. This is the join of REQ-PIS-005, run from a second place.
- The join refuses a waiting account of another audience, and one that carries a claim the account holds with another value. These two refusals also apply to the join at sign-in (REQ-PIS-005).
- The join is written to the audit trail: which account took over which waiting account, and when.

## Trust

The link proves that somebody holds the mailbox. It does not prove that this person asked for the address. Anybody can add anybody's address to their own account, and the mail goes to the address, not to the account. A join on the link alone would hand the attacker's account the victim's children the moment the victim opened the mail (security review H1).

So the join runs only when the confirmation arrives in the confirming account's own session: the bearer's subject is the account's, in the account's organisation, at trust `substantial` or higher. A link opened without a session, or in somebody else's session, confirms the address and joins nothing.

The other side is unchanged: a pending account whose address was verified out of band, with no identity reference of its own, in the same organisation. It must also be for the same audience, and it must carry no claim the receiver holds with another value (security review M3, M1).

Only an active account that signed in through an identity provider receives claims this way.

## Limits

- This helps only when the guardian confirms the same address the school invited her on. With another address nothing joins and nothing tells her so. The invitation link and the code from school (separate changes) close that gap.
- A link opened in a new browser tab has no session, because the portal keeps the session per tab. Then nothing joins. The invitation link and the code (separate changes) cover that case.
- Addresses are compared without regard to case (security review L4). The store is asked for the address as written and in lower case. A waiting account stored with capitals is found only when the database compares without case or the person types the same capitals.

## Not changed

- integriq and what the broker returns.
- The sign-in itself. No BSN is read or stored.
- learniq.
