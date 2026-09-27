# Design: inbox-reply-with-attachments

Read at portaliq development `eeda3fa` and dossiq development on 2026-09-27.

## What is there today

- `src/portal/components/InboxPage.jsx:39` renders the unified inbox from `api.fetchInbox()`: subject, source label, date, body, the WMEBV fields, "View task" for a `taskUuid` (lines 126-133), and "Mark as read". No compose, no reply, no attachments.
- `lib/Controller/ContributionController.php:334` `inbox()` (`GET /portal/api/inbox`, `appinfo/routes.php:247`) calls `PortalInboxReader::aggregateInbox()` (`lib/Service/PortalInboxReader.php:93`), which reads every `kind: inbox` collection (line 104) through the scoped reader and merges the rows with a `_source`.
- `ContributionController.php:370` `markRead()` (`PATCH /portal/api/inbox/{register}/{schema}/{id}/read`, `routes.php:252`) proves the message is the resident's: the collection must be one of their inbox collections (`authorisedInboxCollection()`), `minTrust` must hold, and the row is read through the scope value.
- `ContributionController.php:925` `create()` (`POST /portal/api/collections/{register}/{schema}`, `routes.php:291`) runs a create action: whitelist, `defaults` stamped over the body, the subject's scope stamped on `scopeField`, `minTrust`, and the cross reference guard (`crossRefGuard()`, line 184) before anything is written.
- File fields: an action's `fieldConfigs.<field>` may be `type: file` with `multiple`, `accept` and `maxSizeMb` (`lib/Contribution/FileFieldConfigNormaliser.php`). After a create, the SPA uploads each file with `portalApi.uploadFieldFile()` (`src/portal/lib/portalApi.js:545`, driven by `src/portal/lib/fileFieldSubmit.js`) to `POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}` (`routes.php:309`), and `PortalFieldFileController` attaches it and writes the reference into the field.
- Downloads: `ContributionController::object()` adds `_files` to an owned row only when the collection declares `filesDownload` (lines 626-628), and `downloadFile()` (line 782, `routes.php:315`) streams one after re-proving ownership.
- The guardian thread store: `messageGuardian#*` and `messageStaff#*` (`routes.php:130-138`) on `GuardianMessagingLeafInterface`, implemented by `InAppMessagingLeaf` over portaliq's own `messageThread` and `guardianMessage` schemas. Participation is decided by `MessageThreadAccessGuard::isParticipant()` (`lib/Service/Messaging/MessageThreadAccessGuard.php:108`) from the group fixtures `GroupStaffFixtureReader` and `GuardianAudienceFixtureReader`. Its proposal lists file attachments as out of scope.
- Dossiq development: the citizen `berichten` collection is `kind: inbox` on `portaalBericht`, scoped by `recipientRef`, with `attachments` among its fields. The create action `replyToMessage` writes a `portaalBericht` scoped by `senderRef`, with fields `subject`, `content`, `attachments` and `caseId`, defaults `direction: citizen_to_handler`, and `caseId` a required cross reference to the resident's own cases (dossiq `lib/Portal/PortalContributionProvider.php:591`).

## D1. The reply goes to the case app's message store, not to the guardian thread store

The lane asked whether to reuse the guardian thread store. It is not reused, for four reasons.

1. **The conversation belongs to the case app.** A message to a resident about their case lives in the case app's register (`portaalBericht` in dossiq). The handler reads it there. A reply written to portaliq's `guardianMessage` would sit in a store no handler opens, split from the message it answers.
2. **Participation is a different question.** The thread store decides who may post from group fixtures: which teacher teaches which child's group. A case conversation is decided by the case: the resident who owns it and the handler on it. `isParticipant()` cannot answer that, and bending it to would give one predicate two meanings.
3. **The send path already exists on the case side.** Dossiq declares `replyToMessage`, with a guarded case reference. Using it keeps ADR-046's rule: the case app declares, portaliq serves.
4. **The thread store has no attachments** and was designed to be replaced by an OpenRegister messaging leaf. Building attachments into it now would be built twice.

The two stay separate surfaces. If OpenRegister's messaging leaf later serves both, both move then.

## D2. An inbox collection declares its reply

A `kind: inbox` collection may declare:

```json
"reply": {"action": "replyToMessage", "carry": {"caseId": "caseId"}, "subjectFrom": "subject"}
```

- `action` must be a `type: create` action of the same contribution. Anything else drops the key.
- `carry` maps a field of the reply action to a field of the original message. Only fields the reply action whitelists, and only fields the inbox collection projects, are kept.
- `subjectFrom` names the message field whose value prefills the subject, prefixed "Re: ".

A collection without `reply` shows no reply button. Portaliq's own `portalMessage` declares none.

## D3. The server builds the reply from the message the resident owns

A new route `contribution#reply`, `POST /portal/api/inbox/{register}/{schema}/{id}/reply?collection=`, `PortalProtected`, `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 10, period: 60)]`:

1. Proves the message is the resident's, with exactly `markRead()`'s checks.
2. Resolves the declared `reply.action` from the same contribution. The resident must hold it.
3. Takes the client's fields from the action's whitelist, minus every carried field.
4. Sets each carried field from the original message on the server, overriding anything the client sent.
5. Runs the create path `create()` uses: defaults, scope stamping, `minTrust`, the cross reference guard. That path is extracted from `ContributionController::create()` into a service both call, so there is one write pipeline.
6. Answers with the new reply's id and the action, which the SPA needs for the file upload.

A tampered `caseId` cannot reach the write: it is carried from the message, and the cross reference guard checks it again.

## D4. Files go with the reply through the existing file field

The reply form renders the action's declared `type: file` fields with their `accept` and `maxSizeMb`. After step 6 the SPA uploads each file through `fileFieldSubmit.js` into the new reply. No new upload route.

If an upload fails after the reply was written, the form says: "Your reply was sent, but {name} could not be added. Add it again from the case." (Dutch: "Uw antwoord is verstuurd, maar {name} kon niet worden toegevoegd. Voeg het opnieuw toe bij de zaak.") The reply is not rolled back: the text arrived, and rolling back a message a handler may already see is worse than a missing file.

## D5. Incoming attachments are listed and open

`PortalInboxReader::aggregateInbox()` adds `_files` (id, name, size) to each row of an inbox collection that declares `filesDownload`, the same rule `object()` applies at lines 626-628. `InboxPage.jsx` lists them under the message, each a download through the existing `portalApi.downloadFile()` and `contribution#downloadFile`, with `?collection=`.

## D6. The screen

Under each message with a reply declaration, a "Reply" button (Dutch: "Antwoorden") opens the reply form in place, not in a modal: the subject (prefilled, editable), the text, the file fields, "Send" (Dutch: "Versturen") and "Cancel" (Dutch: "Annuleren"). The form has a heading naming the message it answers. On success it closes and says "Your reply has been sent." (Dutch: "Uw antwoord is verstuurd."). A refusal shows the server's sentence, as the case screen already does.

## Risks

- **Dossiq's `attachments` holds document ids, the file field writes file references.** Until dossiq decides, a handler may see references in a field that expected document ids. The sibling half names it.
- **An empty reply.** The action's own required fields decide; portaliq adds no rule of its own.
- **Rate.** Ten replies a minute per client is generous for a person and low for a script.

## What this change does not do

- It does not touch the guardian thread store or its routes.
- It does not add a sent folder or thread view.
- It does not let a resident start a conversation without a message to answer.
