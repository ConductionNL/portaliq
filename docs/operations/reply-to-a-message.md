---
title: Reply to a message and attach files
sidebar_label: Reply to a message
---

# Reply to a message and attach files

A resident answers a message from the organisation in their portal inbox, adds files to the answer, and opens the files that came with a message.

## Why a reply goes to the case app

The reply is written into the case app's own message store, linked to the case the message was about. The handler reads it there. Portaliq's guardian thread store is not used: it decides who may post from group lists, not from a case, and it has no attachments.

## What the case app declares

An inbox collection names the create action that answers its messages:

```json
"reply": { "action": "replyToMessage", "carry": { "caseId": "caseId" }, "subjectFrom": "subject" }
```

- `action` must be a `type: create` action of the same contribution. Anything else drops the key.
- `carry` maps a field of the reply action to a field of the original message. Only fields the action whitelists and the collection projects are kept.
- `subjectFrom` names the message field whose value prefills the subject, with "Re: " in front.

A collection without `reply`, such as Portaliq's own notices, shows no reply button.

## What the resident sees

Under each message with a reply declaration there is **Antwoorden**. It opens a form in place with the subject (prefilled), the text and the action's file fields, **Versturen** and **Annuleren**. Files follow the field's accepted types and size limit.

After sending, the form closes and says "Uw antwoord is verstuurd." If a file fails after the text arrived, the reply stays and the form says which file was not added.

## What the server does

`POST /portal/api/inbox/{register}/{schema}/{id}/reply?collection=` proves the message is the resident's with the checks of marking it read, and answers 404 for a message that is not theirs. It sets every carried field from the original message and ignores what the client sent for it. The reply is then written through the pipeline every portal create uses: the action's defaults, the required fields, the cross reference guard, the scope stamp and the `minTrust` check. The files are uploaded afterwards into the new reply with the existing file field route.

## Files that came with a message

An inbox collection that declares `filesDownload` lists each message's attached files and lets the resident download them through the scoped download.
