## Why

Proof run 3 (09 Oct) found the conversations hard to reach and unlike the Berichten boards:

- the menu's "Berichten" opened the inbox; the conversations page ("Gesprekken", `/mijn/messages`) only
  appeared in the menu once a thread existed, so a pupil or parent could not write a first message;
- the page was titled "Gesprekken", the board says "Berichten";
- "Nieuw bericht" stood on its own row without a plus; the form had an empty choice and a subject field the
  board does not have; an AI translation choice stood above the list;
- the school's own messages ("Uw afwezigheidsmelding is goedgekeurd") were on another page, without a way
  to what they are about; a conversation had no "Antwoorden";
- dates read "9-10-2026, 02:45:23";
- "Mijn zaken" showed cards titled with raw ids.

## What Changes

- `shellSections()` turns the conversations on when the resident has a contact to write to, also before the
  first thread; the site reads the contacts with the account.
- The page on screen carries the label the portal's menu layout gives its item (`{item, label}`,
  resident-menu-follows-the-boards), so title and breadcrumb read "Berichten".
- The messages page titles itself, with "Nieuw bericht" (with a plus) at the end of the title row.
- The organisation's own messages stand among the conversations, newest first, each with "Nieuw" while
  unread and "Bekijken" to the record it is about (marking it read). A record's tab holds its conversations.
- A conversation card has "Lees het hele gesprek" and "Antwoorden" (opens it and puts the cursor in the reply).
- Dates read "vandaag 8.40 uur", "gisteren 16.05 uur", "1 oktober".
- The form chooses the first contact at once and has no subject field; the translation choice folds away
  under the list ("Berichten in een andere taal").
- A case is never titled with a uuid: its title, reference or case type, else "Zaak".

## For learniq

Declare the menu item as `{"item": "messages", "label": "Berichten"}` and `residentMenu.routes`
`{"berichten": "messages"}`; seed the boards' threads and inbox messages (S).

## Impact

Stacked on #1429. The inbox page stays; a portal that names "inbox" in its menu keeps it.
