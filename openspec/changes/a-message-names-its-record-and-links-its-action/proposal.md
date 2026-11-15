# Proposal: a-message-names-its-record-and-links-its-action

## Why

The boards (8 October 2026) draw notices that belong to something and ask for one act:

- [warmtepompacademie/Berichten](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Berichten): each message names its sender with a job title and what it is about ("bij inschrijving I-2026-0412"); "Alles als gelezen markeren".
- [esdoornveen/Berichten](https://identity.conduction.nl/screens/board?id=esdoornveen/Berichten): tabs "Alles", "Ongelezen (2)", "BPV", "Examens"; the open message "Vraag over je uren van dinsdag 29 september" from "Petra Bakker, Praktijkopleider, Bakker Techniek BV" carries the returned day and the button "Uren aanpassen", with a reply field. The board draws two panes; deviation D-9 keeps one list where a message opens on its own page with the reply below.
- [vaartveld/Berichten](https://identity.conduction.nl/screens/board?id=vaartveld/Berichten): "Kies een tijd voor het mentorgesprek" with slots inside the message. Deviation D-5: the message links to the booking ("Kies een tijd"); the slots stay on the booking page.

`site-messages-per-record` groups conversations per record and leaves notices on the inbox page ("D-9 and D-5 of the plan"). An inbox notice today has a title, a text, a sender and a date (`inbox-reads-each-apps-message-fields`). Lane T2 names "a message tied to an enrolment with a sender job title" and D-5/D-9 on Berichten.

## What Changes

- An inbox collection MAY name three more message fields: `senderRoleField` (a line under the sender, "Praktijkopleider, Bakker Techniek BV"), `aboutField` (a line "Over: {value}", with `aboutLinkField` to the record's page) and `actionField` (an object `{label, href}` the app projects; the site renders it as one button on the opened message, only when `href` is a page of this portal).
- The inbox page MAY declare `tabs`: "Alles", "Ongelezen" (with the count) and tabs over a field of the collection (`tabField`, such as `topic` with BPV and Examens).
- "Alles als gelezen markeren" marks the shown messages read through each collection's own read field (REQ-IMF-002).
- A reply below an opened message, when the contributing app declares a reply action on the collection (the conversation pattern of `site-messages-per-record`).

## Not in this change

- Slots or a form inside a message (D-5): the action button opens the page that holds them.
- Two panes (D-9).
