# Design: inbox-read-receipt-on-request

## Screens

The staff side follows the Zuiddrecht board **PtBericht** ("portaliq: bericht", canvas `5NkFW28vZUUij43xzxHg5a`). Its detail panel has a row "Gelezen" with the value "nog niet", and a "Geschiedenis" list with lines like "In de inbox gezet door dossiq, vandaag, 08.30". This change fills those two places:

| Board element | What it shows |
|---|---|
| Gelezen | "nog niet", or the moment in the board's format ("vandaag, 09.14"). Without a receipt request: "ja" or "nog niet", as the badge does today. |
| Leesbevestiging (new row under Gelezen) | "gevraagd" when `readReceiptRequested` is true. Absent otherwise. |
| Geschiedenis | A line "Gelezen door R. Mulder" with the moment, only when a receipt was asked. |
| Ontvanger | Unchanged for a single message. With a `sendingRef`, a link below it: "Alle ontvangers van deze verzending (18 van 24 gelezen)". |

The resident side follows the board **Berichten** ("Mijn Zuiddrecht: berichten"). Each card there has a title, date, text, link and "Markeren als gelezen".

Two pieces are not on any board yet, and the builder follows this design for them:

- The notice line on a resident's message card. It sits under the text, above the actions, in the card's secondary text style.
- The reader list for one sending. It is the existing Portal Messages index, filtered on `sendingRef`, with columns "Ontvanger", "Gelezen op" and the read badge, and the count above the table.

## Data

`portalMessage` (lib/Settings/portaliq_register.json) gains three optional properties:

- `readReceiptRequested`: boolean, default false. Set by the writer. Never written by mark-read.
- `readAt`: date-time. Written by mark-read only, once.
- `sendingRef`: string. A free id the sending app chooses, shared by every copy of one sending.

The schema version moves from 0.6.0 to 0.7.0. All three are optional, so existing messages stay valid.

## Why the moment is kept only on request

When you opened a letter is personal data. A read flag is enough for the inbox and the unread count. The moment serves the sender only, so portaliq records it only when the sender asked, and tells the resident it does. This follows data minimisation (AVG art. 5 lid 1 sub c).

## Who reads the receipts

ADR-022: portaliq builds no endpoint for the reader list. Staff read it through the Portal Messages index, which is OpenRegister's object list. A sending app (planninq for a class letter) reads `portalMessage` rows filtered on its own `sendingRef` through the OpenRegister object API. Read access stays OpenRegister RBAC, exactly as for the existing index.

## Mark-read

`ContributionController::markRead()` keeps its literal payload. `InboxMessageFields::readPayload()` decides the payload:

1. Portaliq's own `portalMessage` without a request: `{read: true}`.
2. With a request and no `readAt` yet: `{read: true, readAt: now}`.
3. With a request and a `readAt` already: `{read: true}`. The first moment stays.
4. An app inbox naming `readAt`: the current time, unless that field already holds a value.

Rule 4 changes REQ-IMF-002 of `inbox-reads-each-apps-message-fields` for a second open. That is intended: a receipt that moves on every open says when the message was last opened, not when it was read.

The current row has to be read before the write to know whether a moment exists. `writeScoped()` already reads the row to check ownership, so the check adds no extra read.
