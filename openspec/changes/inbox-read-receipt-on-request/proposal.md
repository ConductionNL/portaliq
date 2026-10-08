---
kind: code
depends_on: [inbox-reads-each-apps-message-fields]
---

# Proposal: inbox-read-receipt-on-request

## Why

A teacher sends a letter to the parents of a class and asks for a read receipt. Two days later she wants to know which parents have not opened it yet. WebUntis offers exactly this ("Lesebestätigungen anfordern ... wer die Nachricht bereits gelesen hat"). Portaliq does not.

Portaliq stores a read flag today, and staff see it as a badge (`cmp-inb-read-receipt`, built in `2026-07-23-portal-inbox-v2`). Three things are missing:

- The sender cannot ask for a receipt. Every message gets the same yes or no.
- Nobody records when a message was opened. The flag has no moment.
- A letter to 24 parents is 24 separate messages. Nothing ties them together, so nobody can see that 18 of 24 have read it.

Planninq owns the row that asks for this (`col-read-receipts`) and names portaliq as its owner, because the parent inbox is portaliq's. News items already keep receipts (`NewsReadReceiptService`), but no screen lists the readers, and news is not a message.

## What changes

- **The sender asks for a receipt per message.** `portalMessage` gains `readReceiptRequested` (boolean, default false), `readAt` (date-time) and `sendingRef` (string). An app that writes a message sets them through OpenRegister, like every other field it writes.
- **The moment is recorded only when asked.** Mark-read writes `readAt` once, on the first open, and only on a message that asked for a receipt. Without a request it writes `read: true` as before. A second open keeps the first moment.
- **The resident is told.** A message that asks for a receipt says so in Berichten: "De afzender ziet wanneer u dit bericht hebt geopend."
- **Staff see the moment on the message.** On the message page (board `PtBericht`) the "Gelezen" row shows "nog niet" or the moment, and "Geschiedenis" gets "Gelezen door {ontvanger}".
- **Staff see who read one sending.** Messages that share a `sendingRef` are one sending. The Portal Messages index filters on it and shows "Gelezen op" per recipient, with a count "18 van 24 gelezen". The sending app reads the same rows through the OpenRegister object API.
- **An app's own inbox joins in.** `messageFields` may also name `readReceiptRequested`, so an app's inbox shows the same notice, and mark-read keeps the first moment in the app's own `readAt` field.

## Rows covered

- portaliq `cmp-inb-read-receipt-request` (added by this change).
- planninq `col-read-receipts` links here as `portaliq/inbox-read-receipt-on-request`.

## Out of scope

- A reader list for news items. News keeps its own receipts in `newsItem.readReceipts`; a screen for them is a separate change.
- Guardian direct threads. `guardianMessage.readBy` already records every reader.
- Reminding the parents who have not read. A sending app can do that with its own rule.
- Writing the message itself. The sending app (planninq, learniq, dossiq) composes and sends; portaliq delivers and records.
