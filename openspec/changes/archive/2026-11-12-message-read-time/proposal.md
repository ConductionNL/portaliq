# Proposal: message-read-time

## Why

[zuiddrecht/Berichten](https://identity.conduction.nl/screens/board?id=zuiddrecht/Berichten) shows under a message the resident sent: "Leesbevestiging gevraagd: gelezen door de gemeente op 5 oktober 2026, 10:12". A message keeps `readBy`, who read it, but not when. So the site could not say when her message was read; round 2 left it as "no data".

## What changes

- The first time a participant other than the sender reads a message, the message gets `readAt`, written once. The sender's own read never sets it.
- On the messages page, the resident's own message reads "Gelezen door {name} op {date and time}" once it was read; nothing while unread.
- Register 0.106.0, guardianMessage 0.3.0: `readAt` (additive).

## Capabilities

### Modified capabilities

- `guardian-direct-messaging`: ADDED requirements for the read moment and the line under the sender's message.

## Impact

- `lib/Service/Messaging/InAppMessagingLeaf.php` (`markOneMessageRead`, `withReadAt`), `lib/Settings/portaliq_register.json`.
- `src/site/pages/inbox/conversations.js` (`readLine`), `MessagesPage.vue`.

## Not in this change

- A message read before this change has no `readAt` and shows no line.
- The board's lead-in "Leesbevestiging gevraagd:" is for the inbox list, where a sender asks for a receipt; a conversation message always records it, so the line starts with "Gelezen door".
