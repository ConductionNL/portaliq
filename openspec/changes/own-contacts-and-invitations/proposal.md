---
kind: code
---

# Proposal: own-contacts-and-invitations

## Why

Sanne works on her debts with a caseworker from the municipality and a counsellor from a foundation. Her father helps her too. In the portal she cannot name any of them. Every message goes to "the organisation", and she cannot invite her father to look along.

Open Inwoner keeps a contact list per resident (`src/open_inwoner/accounts/views/contacts.py:22`, `:57`): add someone, invite them by e-mail, approve each other. Portaliq invites people into a portal only from the staff side (`portalInvitation`, identity-staff-account-screens). The Zuiddrecht boards **Contacten** and **ContactUitnodigen** draw the resident's version.

## What changes

- **Mijn contacten.** A page under Mijn Zuiddrecht lists the resident's contacts with filter chips Alle, Begeleider, Contact and Organisatie, each with "Bericht sturen" and "Verwijderen".
- **Mutual approval.** Someone becomes a contact only after both sides approve. A request shows under "Wacht op goedkeuring" with "Accepteren" and "Weigeren".
- **Invite someone.** The dialog "Iemand uitnodigen" takes an e-mail address and an optional message. Without an account the person gets an invitation link, valid 14 days, that pre-fills the registration. With an account they get an approval request instead. A pending invitation shows "Opnieuw versturen" and "Intrekken".
- **A caseworker is a Begeleider.** Staff of the organisation linked to a resident show as Begeleider, with the plans they share.
- **What a contact sees.** Only the name, the messages sent to them and the plans they are added to. Never cases, tasks or personal data.

## Rows covered

- `id-own-contacts` (decisions 101 and 102), screen Contacten and ContactUitnodigen.

## Out of scope

- Shared plans. `cmp-tsk-plan` is decided-no; the "In 1 plan met u" line shows only when a plan app contributes it.
- Mandates. Acting for someone stays `cmp-sig-machtiging`; a contact gets no access to cases.
