# Proposal: confirmed-address-joins-the-waiting-account

## Why

A school invites a guardian through learniq. Learniq asks Portaliq for a pending account on the guardian's verified address and writes the `learniq.guardianRef` claim on it. The guardian reaches that account when her sign-in carries the same verified address (REQ-PIS-002, REQ-PIS-005).

A sign-in through the integriq broker carries no address: the DigiD answer holds a pseudonym, the provider and the trust level, and nothing else. The guardian gets a new, empty account. The invited account stays pending and she sees no child.

The portal then asks her for an e-mail address, and she confirms it through a mailed link. That confirmation proves she holds the address. Today nothing is done with that proof.

## What changes

- When a person follows the confirmation link for an address, Portaliq looks for a pending, email-only account with that verified address in the same organisation.
- When it finds one, the claims of the pending account are added to the account that confirmed the address, and the pending account is withdrawn with a reason. This is the join of REQ-PIS-005, run from a second place.
- The join is written to the audit trail: which account took over which waiting account, and when.

## Trust

Nothing new is trusted. REQ-PIS-002 and REQ-PIS-005 accept an address the broker says it verified. Here Portaliq verified the address itself: it mailed a one-time secret to it, and the person brought the secret back. The other side is unchanged: a pending account whose address was verified out of band, with no identity reference of its own, in the same organisation.

Only an active account that signed in through an identity provider receives claims this way.

## Limits

- This helps only when the guardian confirms the same address the school invited her on. With another address nothing joins and nothing tells her so. The invitation link and the code from school (separate changes) close that gap.
- The addresses are compared as written, as REQ-PIS-002 compares them.

## Not changed

- integriq and what the broker returns.
- The sign-in itself. No BSN is read or stored.
- learniq.
