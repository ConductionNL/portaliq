# Design: own-contacts-and-invitations

## Screens

The page follows the Zuiddrecht board **Contacten** ("Mijn Zuiddrecht: mijn contacten"); the dialog follows **ContactUitnodigen** (canvas `5NkFW28vZUUij43xzxHg5a`).

| Board element | What it does |
|---|---|
| Heading "Mijn contacten", button "Iemand uitnodigen" | opens the dialog |
| Intro "Mensen en organisaties met wie u samenwerkt. Met een contact kunt u berichten sturen en samen een plan maken." | static |
| "Wacht op goedkeuring" | incoming requests (Accepteren, Weigeren) and own pending invitations (Opnieuw versturen, Intrekken), with the date |
| "Uw contacten" with chips Alle, Begeleider, Contact, Organisatie and counts | the approved contacts, filtered by `role` |
| Contact row: initials, name, role tag, line (organisation or relation, shared plans), "Bericht sturen", "Verwijderen" | one `portalContact` |
| "Wat ziet een contact van u?" | static notice |
| Dialog fields "E-mailadres" and "Bericht (niet verplicht)", the "Wat gebeurt er na het versturen?" list, "Uitnodiging versturen", "Annuleren" | creates the invitation |

The dialog lives in its own file under `src/site/modals/` (ADR-004 modal isolation).

## Data

New schema `portalContact` (schema.org `ContactPoint` between two `Person`s) in `lib/Settings/portaliq_register.json`:

- `owner` (subjectRef of the resident who holds the row), `contactRef` (the other account's subjectRef, empty while invited), `role` (`begeleider`, `contact`, `organisatie`), `displayName`, `line`, `state` (`requested`, `invited`, `approved`, `declined`, `withdrawn`), `email` (invitation only), `message`, `tokenHash`, `sentAt`, `expiresAt`.

A link is two rows, one per side, so each side reads only its own rows through the portal's scoped read (`scopeField: owner`). Approval sets both rows to `approved`.

Begeleider rows are written by the case or plan app (staff side), never by the resident: the resident's write whitelist allows only `contact` rows.

## Invitation

The invitation reuses `PortalIdentityMailer` and the ways-in link pattern (portal-ways-in REQ-IWI-001): a hashed token, valid 14 days, mailed with the template `contact-invitation`. Following the link opens self-registration with the e-mail filled in; on activation the invited row becomes `approved` on both sides, without a second approval. When the address belongs to an existing account, no mail with a link is sent: that account gets a `requested` row and a portal notification.

Rate limit: at most 10 invitations per resident per day, and never more than one open invitation per address.

## Messages

"Bericht sturen" opens a thread with that contact in the existing message thread model (`messageThread`), restricted to approved contacts. Removing a contact closes new messages but keeps the history.
