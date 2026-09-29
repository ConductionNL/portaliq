---
title: Letters to the government message box
sidebar_label: Letters to the message box
description: How a letter in the portal inbox also reaches the resident's government message box, what an organisation sets, what a case app declares, and what the resident sees
---

# Letters to the government message box

A municipality sends a decision to a resident through the portal. Many residents never open the portal but do read their government message box, such as MijnOverheid Berichtenbox. With this channel the letter goes to both. Portaliq does not talk to the message box itself: integriq's digital post adapter does.

## What the organisation sets

The organisation's presentation settings gain one block:

```json
"messageBox": {"sourceId": "berichtenbox-venray", "label": "MijnOverheid Berichtenbox"}
```

- `sourceId` is the slug of the digital post source configured in integriq.
- `label` is the name residents read in the portal.

Without both, the channel does not exist for that organisation: residents see no choice for it and nothing is sent.

## What a case app declares

The case app's inbox collection names a method on its own portal provider:

```json
{"id": "berichten", "kind": "inbox", "messageBox": {"recipientProvider": "messageBoxRecipient"}}
```

Portaliq calls `messageBoxRecipient($messageId)` server-side for each new message in that collection. The method returns the recipient's identity, such as a citizen service number, or `null` when the message stays in the portal. Portaliq passes the value to integriq and keeps it nowhere: not in a record, not in a log line, not in any answer to the browser.

Return `null` for a letter your app already sends to the message box itself, for example from a compose dialog. Otherwise the resident gets it twice.

The name must be a plain method name and may not be one of the contract's own methods, the same rule as a timeline method. Portaliq drops a declaration that breaks it.

### The letter's text and subject

Portaliq reads the letter from the message the resident's own inbox shows. The text comes from `bodyField` when the collection names one, else from the first of `body`, `content` or `text` that holds text. The subject comes from `subjectField`, else `subject` or `title`:

```json
{"id": "berichten", "kind": "inbox", "messageBox": {"recipientProvider": "messageBoxRecipient", "bodyField": "content", "subjectField": "subject"}}
```

A message without text is never sent as an empty letter. Portaliq asks integriq for nothing, records the attempt as failed with the code `empty_body`, and logs a warning that names the message, not the resident.

## What the resident sees

Under a message that reached the message box, the inbox shows "Also sent to MijnOverheid Berichtenbox." It shows only once integriq reports the letter delivered or read. A letter that is on its way, failed, or was sent through a simulated binding shows nothing to the resident.

In **Inbox**, **Notification settings** holds one more checkbox when the organisation offers the channel: "Also send letters to MijnOverheid Berichtenbox". It is on until the resident switches it off. Switched off, portaliq asks integriq for nothing on their behalf.

## What the administrator sees

Every attempt is a `portalNotification` row with channel `messageBox`:

| Status | Meaning |
|---|---|
| `sent` | Integriq took the letter and answered with a message id (`externalMessageId`). |
| `delivered`, `read` | Integriq reported the letter delivered, or read. |
| `simulated` | Integriq's binding sends nothing. Never shown to the resident as delivered. |
| `failed` | No letter left. `refusalCode` says why: `not_installed` when integriq is absent, `unhandled` when nothing answered, or integriq's own refusal code. |

Until integriq's live binding to the message box is in place, every letter is `simulated` or `failed`. Check the log for your first letters after the binding goes live.
