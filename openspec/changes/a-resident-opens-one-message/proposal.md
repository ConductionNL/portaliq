# Proposal: a-resident-opens-one-message

## Why

The board [BerichtDetail](https://identity.conduction.nl/screens/board?id=portaliq/BerichtDetail) ("Mijn Zuiddrecht: bericht") draws one opened message. Sanne de Vries reads the message "Het besluit op uw Woo-verzoek is gepubliceerd" on its own page. The page shows:

- "Terug naar berichten" above and below the message.
- The title, "Ontvangen op 7 oktober 2026 om 09:12", the sender and the case it is about, and a "Gelezen" badge.
- A case card "Verlichting fietspad Lindelaan" with "Naar uw zaak".
- "Over dit bericht": Van, Over zaak, Ontvangen, Aard, Rechtsgevolg and Termijn.
- The message text.
- "Bijlagen": two files, each with its name, type and size.
- "Naar uw zaak" again at the bottom.

Live has no such page. Lane PT-T2 found that `/mijn/inbox` is the list only: `InboxPage.vue` renders every message in full inside the list, and no route opens one message. There is also no server endpoint that reads one inbox message. The list reads them all through `GET /portal/api/inbox`.

## What changes

- A message in the inbox list links to its own page under the inbox page: `/mijn/<inbox page>/<source>/<id>`.
- A new endpoint `GET /portal/api/inbox/{register}/{schema}/{id}` reads one message. It proves the message is the resident's before it reads it, with the same checks as mark-read. A message that is not theirs gets the same 404 as one that does not exist.
- The message page follows the board: back link, header with the read badge, the case card, "Over dit bericht", the text, the attachments and the case button.
- Opening an unread message marks it read through the existing mark-read. The menu's unread count drops by one.
- The reply form (REQ-IRA-001) and the one action button (`actionField`) show on this page when the collection declares them. The board's Woo decision declares neither, so it shows neither.

## Not in this change

- Two panes, list and message side by side (deviation D-9 of `a-message-names-its-record-and-links-its-action` keeps one list and a page per message).
- Removing the full message from the list rows. The list keeps what it shows today; a later change can shorten the rows once the page exists.
- Staff's message page (board PtBericht). That page exists in the admin.

## Impact

- portaliq only. The contributing apps (dossiq first) change nothing: the page reads the fields their inbox collections already project.
- Specs: new capability `portal-message-detail`.
- Code: `appinfo/routes.php`, `ContributionController`, `PortalInboxReader`, a new `src/site/pages/inbox/MessagePage.vue`, `inbox.js`, strings in `l10n/`.
