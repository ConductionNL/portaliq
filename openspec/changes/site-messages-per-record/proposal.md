# Proposal: site-messages-per-record

## Why

The school boards (school-design, 5 October 2026; plan `PORTAL-PLAN.md` item W2-5, gap G-24) draw "Berichten" as conversations per child: tabs "Alle berichten", "Over Vera", "Over Sami"; each conversation a card with the teacher's name, when, about whom, a subject line, the start of the newest message and "Nieuw" until it is read; and below them "Een bericht aan de leerkracht" with a "to" field ("Meester Daan, over Vera (groep 7)") and the message. Vaartveld and Esdoornveen draw the same for a pupil or student and her mentor.

What portaliq has today (`guardian-direct-messages`):

- threads and messages (`messageThread`, `guardianMessage`), a guardian may read, post and mark read;
- a guardian may start a thread only with a staff member that the interim `groupStaffFixture` says teaches a group the guardian reaches. Nothing writes that fixture for a real school, so no parent can start a conversation;
- the site's messages page is read only: no new message, no reply, no subject, no name of who wrote, no grouping per child.

## What changes

- **Contract key `contacts` on a collection**: `{provider, recordLabelFields?, composeLabel?, composeHint?}`. For each row of that collection the resident owns, portaliq calls the app's provider method with the row id; the app answers `[{staffRef, name, role?}]`, the people the resident may write to about that row. The provider name passes the timeline rule, and an answer that does not fit is dropped (`MessageContactsKeys`).
- **`GET /api/messages/contacts`** (`MessageContactReader`): the resident's contacts per record, with the record's words ("Vera, Groep 7") and the form's own heading and hint.
- **`POST /api/messages/threads` with `recordRef`, `title` and `body`**: the contact is proven again on the server (the record read through the scoped reader, the provider asked again); only then the thread and its first message are stored. Without `recordRef` the older fixture rule applies unchanged.
- **`messageThread` 0.2.0** (register 0.68.0): `recordRef`, `recordLabel`, `title`, `staffName`, `staffRole`, copied when the thread starts.
- **The thread list** carries a `summary`: how many messages the reader has not read, the newest message and when.
- **The site's messages page**: tabs per record, a card per conversation that opens with its messages and a reply form, "Nieuw" until opened, a "Nieuw bericht" button and the form to write to a contact; opening a conversation marks it read. Its calls go through one new `messaging()` door on the portal API, and the page's own logic stays in its lazy chunk (`src/site/pages/inbox/conversations.js`).

This page no longer draws conversations as the action rows of REQ-SMO-004: the boards draw cards with a subject, a preview and a reply.

## Not in this change

- **The staff side on screen.** Staff reply through the existing `/api/staff/messages/*` endpoints (a Nextcloud user is the staff member, as before); there is no staff screen for these threads in portaliq. The school app may show them; see the learniq change.
- **Notices in the same list** (a grade, a confirmed conference time): they stay on the inbox page. D-9 and D-5 of the plan.
- **The preview in the reader's language**: the opened conversation is translated as before; the card shows the newest message as written.
- A push or mail on a new message.

## Impact

- `lib/Contribution/MessageContactsKeys.php` (new), `CollectionConfigNormaliser.php`; `lib/Service/Messaging/MessageContactReader.php` (new), `GuardianMessagingLeafInterface.php`, `InAppMessagingLeaf.php`; `lib/Controller/MessageGuardianController.php`; `appinfo/routes.php`; `lib/Settings/portaliq_register.json` (messageThread only).
- `src/shared/portalApi.js` (`messaging()`), `src/site/pages/inbox/{MessagesPage.vue,conversations.js}`.
- Tests: PHPUnit `MessageContactsKeysTest`, `MessageContactReaderTest`, `InAppMessagingLeafTest`; node `tests/site-messages-per-record.spec.mjs` (`check:site-messages-per-record`), `tests/site-inbox-pages.spec.mjs` updated.
