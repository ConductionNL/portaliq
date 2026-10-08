---
kind: code
depends_on: []
---

# Proposal: inbox-reads-each-apps-message-fields

## Why

A case handler's message from dossiq reached the resident's inbox as a subject line only (portaliq#702). The text, the date and the attachments never showed.

The inbox reads `body`, `receivedAt` and `read`: the field names of portaliq's own `portalMessage`. Nothing obliges a contributing app to use them. `IPortalContributionProvider` names no inbox row fields, and the apps differ:

- dossiq `portaalBericht`: `content`, `sentAt`, `readByRecipientAt`, `attachments`.
- dossiq `supplierMessage`: `body`, `sentAt`, `attachmentRefs`.
- learniq `grade-notification`: none of the three.

So the text was blank, the date was blank, every dossiq message sorted last and stayed unread. Mark-read wrote `read: true`, a property `portaalBericht` does not declare.

Portaliq already lets an app name its own fields for the message box letter (`messageBox.bodyField`, "dossiq keeps the text in `content`"). This change applies the same idea to the inbox itself.

## What changes

- **An inbox collection names its message fields.** `messageFields: {subject, body, receivedAt, readAt, attachments}`, each a plain field name of that collection. Portaliq copies them onto the inbox row it serves, so the screen keeps one shape.
- **Read state comes from a date.** With `readAt` named, a message is read when that field holds a value.
- **Mark-read writes the named field.** With `readAt` named, mark-read writes the current time into that one field. Without it, it writes `read: true` as before. Nothing from the request body is ever written.
- **Attachments show and download.** An inbox collection that declares `filesDownload` gets each message's files listed, and the inbox offers each one as a download through the existing scoped download (inbox-reply-with-attachments T04 and T07).

## Out of scope

- Replying to a message (inbox-reply-with-attachments T01 to T03, T05, T06).
- Renaming dossiq's or learniq's schema fields. Stored data keeps its names.

## Sibling halves

- **ConductionNL/dossiq** declares `messageFields` and `filesDownload: true` on its `berichten` inbox collection, and `messageFields` on its supplier `messages` collection.
