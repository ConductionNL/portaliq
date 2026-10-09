## ADDED Requirements

### Requirement: The messages item opens the conversations under the board's title

The site MUST offer the conversations page when the resident takes part in a conversation or has a
contact to write to. The page's title and breadcrumb MUST use the label the portal's menu layout gives
the item, when it gives one.

#### Scenario: A first message from an empty page
@e2e exclude Unit test in node: tests/site-look/mijn-messages-follow-the-boards.spec.mjs
- GIVEN a guardian without any conversation and with Meester Daan as a contact
- WHEN she opens her own area
- THEN the menu offers the conversations, titled "Berichten" when the portal labels them so, with a form to write to Meester Daan

### Requirement: The messages page reads as the boards

The page MUST show its title with "Nieuw bericht" at the end of the title row. It MUST list the
organisation's own messages among the conversations, newest first, each with "Nieuw" while unread and a
"Bekijken" link to the record it is about. A conversation MUST offer "Lees het hele gesprek" and
"Antwoorden". Times MUST read "vandaag 8.40 uur", "gisteren 16.05 uur" or "1 oktober". The form MUST
choose the first contact at once and MUST NOT ask for a subject.

#### Scenario: The approved absence among the conversations
@e2e exclude Unit test in node: tests/site-look/mijn-messages-follow-the-boards.spec.mjs
- GIVEN an inbox message "Uw afwezigheidsmelding is goedgekeurd" linked to the absence record
- WHEN the guardian opens "Berichten"
- THEN the message stands as a card from "De Wilgenboom" with "Bekijken", which opens the absence

### Requirement: No title is a raw identifier

A case card MUST NOT show a uuid as its title: it MUST read its title, reference or case type, else "Zaak".

#### Scenario: A case without a name
@e2e exclude Unit test in node: tests/site-look/mijn-messages-follow-the-boards.spec.mjs
- GIVEN a case row with only a uuid
- WHEN "Mijn zaken" lists it
- THEN its card is titled "Zaak"
