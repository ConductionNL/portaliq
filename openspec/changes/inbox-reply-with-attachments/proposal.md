# Proposal: inbox-reply-with-attachments

## Why

A resident reads a message from their municipality in the portal inbox and cannot answer it there. They cannot add a file to anything they send, and a file the organisation attached to its message is invisible to them.

**Row `cmp-inb-reply`**, "Reply to a message from the organisation inside the portal." The matrix `built.evidence`: "grep reply/compose in src/portal: 0 hits; InboxPage.jsx (full file read) has no compose/reply control". The `built.note`: "No citizen-facing reply exists anywhere. A staff 'reply' is possible only as a manually created, unthreaded portalMessage through the generic admin object form, there is no button, no thread, no quoting of the original message." No demand row. The one competitor cell rated `yes`, quoted from `gap-rows.json`:

- xxllnc Zaken PIP: "frontend-mono/packages/communication-module/src/components/Thread/Thread.tsx:86 getThreadReplyability, ReplyForm [reached on /pip/berichten]". No URL recorded.

The lane built this row as the prerequisite of the next one: attaching a file needs a send path.

**Row `cmp-inb-attach`**, "Attach a file to a message you send." The matrix `built.evidence`: "lib/Settings/portaliq_register.json:629-732 portalMessage schema has no file/attachment property; InboxPage.jsx (full file read) has no upload control or attachment renderer". The `built.note`: "dossiq's portaalBericht declares an 'attachments' field to portaliq (see inb-dossiq-portaalbericht) but nothing renders it, so even a sibling-supplied attachment is invisible." No demand row. The competitor cells rated `yes`:

- Open Inwoner Platform: "src/open_inwoner/accounts/views/inbox.py:190 and :241 files accepted when SiteConfiguration.allow_messages_file_sharing; src/open_inwoner/accounts/views/inbox.py:253 InboxPrivateMediaView [reached on Mijn berichten, message form; behind allow_messages_file_sharing]". No URL recorded.
- xxllnc Zaken PIP: "frontend-mono/packages/communication-module/src/components/MessageForm/Pip/Pip.formDefinition.ts:96 attachments [reached on /pip/berichten new message]". No URL recorded.

Reading dossiq development for this change found its citizen contribution already declares the send half: a create action `replyToMessage` on `portaalBericht`, with `caseId` guarded as a cross reference to the resident's own cases and `attachments` among its fields (dossiq `lib/Portal/PortalContributionProvider.php:591`). No portaliq screen offers it next to a message.

## What changes

- **An inbox collection names its reply.** The case app says which of its own create actions answers a message from that collection, and which fields of the message carry over, such as the case.
- **"Reply" under a message.** The inbox shows a reply button on every message from such a collection. It opens a short form: the subject, prefilled, and the text.
- **The link to the case is taken from the message, not from the browser.** Portaliq reads the original message through the resident's own scoped inbox read and copies the declared fields from it on the server.
- **Files go with the reply.** The reply form offers the action's declared file field. Files are uploaded into the reply the way a file field already works for any portal create.
- **Files that came with a message open.** An inbox collection that declares `filesDownload` gets each message's attached files listed and downloadable, through the existing scoped download.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `cmp-inb-reply` | Reply to a message from the organisation inside the portal. | partial | A reply control on the message, sent to the case app's own store, linked to the right case. |
| portaliq | `cmp-inb-attach` | Attach a file to a message you send. | no | A file field on the reply, and incoming attachments shown and downloadable. |

## Existing work it builds on

- `openspec/changes/archive/2026-07-23-portal-inbox-v2`: the unified inbox (`PortalInboxReader`), `kind: inbox` collections and the scoped mark-read that proves a message is the resident's.
- `openspec/changes/portal-create-cross-refs` (open): the cross reference guard that keeps `caseId` pointing at one of the resident's own cases.
- `openspec/changes/assignment-portal-file-upload` (open): the `type: file` field on an action and `POST /portal/api/collections/{register}/{schema}/{id}/fields/{field}`, reused for the attachments.
- `openspec/changes/archive/2026-07-23-portal-document-download`: `filesDownload` and the scoped download, reused for incoming attachments.
- `openspec/specs/portaliq-leaf-integrations/spec.md`, scenario "The visitor's reply path stays the portal edge" (line 88): the reply is a portal write.
- `openspec/changes/guardian-direct-messages` (open): a separate thread store for guardians and teachers. This change does not use it; design.md D1 says why.

## Out of scope

- A sent folder. The resident's reply lands in the case app, which is scoped by who received it; showing sent replies needs a collection the case app does not declare today.
- Replying to portaliq's own messages (receipts, task announcements). They declare no reply, so they get no button.
- Starting a new conversation without a message to answer.
- Virus scanning uploads. The portaliq matrix row `dem-cl-upload-virus-scan` is owned by `ConductionNL/nextcloud`, and the lane treats it as Nextcloud server's.

## Sibling halves

- **ConductionNL/dossiq owes** the `reply` declaration on its `berichten` inbox collection, naming `replyToMessage` and carrying `caseId`; a `type: file` entry for `attachments` in `replyToMessage`'s `fieldConfigs`; `filesDownload: true` on `berichten`, with the files it sends stored in the message object's own folder. Its `portaalBericht.attachments` is described as "OpenRegister document ids attached to the message" (dossiq `lib/Settings/register.d/50-zaakportaal.json`), while portaliq's file field writes file references. Whether dossiq keeps both or maps one onto the other is dossiq's decision.
