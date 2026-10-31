---
title: Own contacts and invitations
sidebar_label: Own contacts
---

# Own contacts and invitations

A resident keeps a list of people and organisations they work with, invites someone by e-mail, and answers requests from others. A contact sees a name and, later, messages. A contact never sees a case.

## Switch it on

The page is off by default. Set `contactsEnabled` to true on the portal record. Signed-in residents of that portal then get **Mijn contacten** in their menu.

## How a link between two people works

A link is two rows of the `portalContact` schema, one per side, so each side reads only its own rows. Approval sets both rows to `approved`.

| Situation | What happens |
|---|---|
| The address has no account | The resident gets a mailed link, valid for 14 days. Only a hash of its secret is stored. |
| The address has an account | No link is mailed. That account sees a request under **Wacht op goedkeuring** and accepts or declines. |
| The invited person follows the link and signs in | The secret is handed back once. Both rows become `approved`, without a second approval. |
| The request is declined | The requester reads only "Niet geaccepteerd". |

The answer to an invitation never says whether the address has an account.

## Limits

- 10 invitations per resident per day.
- One open invitation per address, and none to an address that is already a contact.
- A message of at most 500 characters.
- A link that is expired, used or not the invited person's own gives the same answer as a link that never existed.

## Routes

All routes need a portal session and read only the bearer's own rows.

| Route | What it does |
|---|---|
| `GET /portal/api/contacts` | The contacts, what waits and the counts per role. |
| `POST /portal/api/contacts/invite` | Invite by e-mail address, with an optional message. |
| `POST /portal/api/contacts/{id}/respond` | Accept or decline a request. |
| `POST /portal/api/contacts/{id}/resend` | Send an invitation again with a new link. |
| `POST /portal/api/contacts/{id}/withdraw` | Take an invitation back. |
| `DELETE /portal/api/contacts/{id}` | Remove an approved contact on both sides. |
| `POST /portal/api/contacts/accept-invitation` | Hand back the secret of an invitation link. |

## Not built yet

- Sending a message to a contact (task T07).
- Filling in the e-mail address on the registration form from the link (the secret is kept and handed back after sign-in instead).
- A `contacts` collection in Portaliq's own contribution (T02): the page uses its own routes, which keep the owner scope on the server.
