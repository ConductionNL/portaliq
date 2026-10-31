# Proposal: invitation-joins-an-unbound-account

## Why

Proof run 3 on the school portal found a guardian who could never accept her invitation.

1. She signed in once with DigiD before the school invited her.
2. That sign-in made her own account, with the sign-in route's audience: `client`.
3. Later the school invited her through learniq. Learniq made a `pending` account with the `parent` audience and her guardian claim.
4. She followed the link and signed in. The redeem found her `client` account and refused: another audience (security review M3).
5. She landed on "Welkom" with nothing, and every later try got the same refusal.

The audience check is right for a supplier who tries to take over a parent's invitation. It is wrong for a person whose own account never had an audience chosen for her.

## What changes

- A redeem of an invitation's mailed link may join the waiting account into an account of another audience, when that account is the person's own and still unbound. A code from a paper letter never does: anyone in the house can read a letter.
  - The invitation's audience is on the organisation's allow-list: `parent` unless the organisation sets `unboundAudiences` in its presentation override. `supplier` is never on it.
  - The account names one natural person (DigiD or eIDAS) and carries its identity reference.
  - Nobody provisioned it: no app, no clerk, no self-registration.
  - It holds no claims yet, and it is no company account (`supplier`).
  - the session is at trust level substantial or higher;
  - everything else the join asks still holds: the waiting account is pending, has no identity reference, sits in the same organisation, and carries no conflicting claim.
- The account then takes on the waiting account's audience, with its claims and address, in the same write. The waiting account is withdrawn as before, and the audit trail records the claim as before.
- A move leaves two traces. The audit trail gets an `audience` row with the old and the new audience. The invited address gets a mail: the invitation was accepted on that date, and who to contact if that was not you. The mail holds no secret and no link.
- The redeem route answers the audience and a reissued bearer when the account's audience moved. The new bearer carries the new audience's role (`parent:read`), never the old one's. The rotation records its audit row before it revokes the old bearer, and a failed revoke is logged, so a late failure never leaves the person signed out. The site stores the new bearer and reads the session again, so the parent pages open without a second sign-in. A reissue that fails costs nothing: the next sign-in carries the account's audience.

## Trust

The mailed secret is still the proof, as in REQ-PIS-008. The session adds who she is. A leaked or forwarded link could be redeemed by anyone with DigiD and a fresh account, so the move is limited to the allow-list, and the invited address hears about it. Nothing here joins on an address alone: the join at sign-in (REQ-PIS-005, REQ-PIS-010) and a confirmed address (REQ-PIS-006) keep refusing another audience.

Why this and not "no account before an invitation": a DigiD answer through the broker carries no address. Without her own account she could never sign in to hand the secret back.

The review points stay closed:

- H1: the join runs only inside the redeem of a secret by a resolved session.
- M1: a conflicting claim still answers `409` before anything is spent. An account that holds any claim does not take on an audience at all.
- M2: the adoption happens inside the existing lock, after the waiting account is read again.
- M3: a company account or identity never takes over a person's invitation, and a person never moves into the company audience.
- M4: unchanged; the secret and code hashes are the existing ones.

## Known, not changed here

- L5: a lost update outside the redeem locks, inherited from the join at sign-in.
- L7: an account that moved from `client` to `parent` no longer sees pages declared for `client` only.

## Not changed

- What the broker returns, and the sign-in itself.
- An account that already holds a claim, or one an app or clerk provisioned, keeps its audience. Its owner gets the same refusal as before.
- The invitation, its secret, the code for a letter and the attempt limits.
