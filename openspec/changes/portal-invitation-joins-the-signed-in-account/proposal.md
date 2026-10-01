# Proposal: portal-invitation-joins-the-signed-in-account

## Why

Found while testing a primary school parent portal end to end (2026-09-30), and reproduced on a clean test guardian on 2026-10-01.

A school invites a guardian through learniq (`GuardianPortalInvitation`, learniq #1565). Learniq asks Portaliq for a pending account for the guardian's verified address and writes the `learniq.guardianRef` claim on it. The guardian's first sign-in finds that account through the verified address (REQ-PIS-002), so she sees her child.

A guardian who signed in once before the invitation already has an active account. Her next sign-in finds that account on its identity reference and stops there. The pending account with the claim is never reached, so she sees no child and the school sees two accounts for one person.

## What changes

- When a sign-in finds an account on its identity reference and the broker says it verified an address, Portaliq looks for a pending, email-only account with that verified address in the same organisation.
- When it finds one, the claims of the pending account are added to the account that signed in, and the pending account is withdrawn with a reason.
- A claim the signed-in account already holds is kept. The join adds what is missing and rewrites nothing.

## Trust

The match is the one REQ-PIS-002 already uses to activate a waiting account: an address the broker verified, against a pending account whose address was verified out of band, with no identity reference of its own, in the same organisation. Nothing new is trusted. A pending account on an identity reference, one whose address nobody verified, one in another organisation, and a sign-in without a verified address are all left alone.

## Not changed

- A broker that supplies no verified address (integriq's DigiD envelope carries none) joins nothing, exactly as it activates nothing by address today.
- `provision()` still creates a pending account for an address it does not know. Matching an active account at invitation time would trust addresses stored on accounts by other routes, which REQ-PIS-002 does not.
