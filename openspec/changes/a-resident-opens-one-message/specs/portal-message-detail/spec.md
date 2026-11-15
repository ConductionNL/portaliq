## ADDED Requirements

### Requirement: A resident MUST be able to open one message on its own page (REQ-ROM-001)

Each message in the inbox list MUST link to its own page at `/mijn/<inbox page>/<source>/<id>`. The page MUST show the message's title, the moment it was received, the sender and the record it is about. It MUST offer "Terug naar berichten" above and below the message, leading to the inbox page.

#### Scenario: Sanne opens the Woo decision
- GIVEN Sanne de Vries has the message "Het besluit op uw Woo-verzoek is gepubliceerd", received on 7 October 2026 at 09:12, from Gemeente Zuiddrecht about case 2026-0082
- WHEN she follows its title in Berichten
- THEN its page shows that title, "Ontvangen op 7 oktober 2026 om 09:12" and "Van Gemeente Zuiddrecht, over zaak 2026-0082"
- AND "Terug naar berichten" brings her back to Berichten

#### Scenario: A cold load opens the same page
- GIVEN Sanne copied the address of that message's page
- WHEN she opens it in a new tab while signed in
- THEN the same message page shows

### Requirement: The server MUST prove a message is the resident's before it reads it (REQ-ROM-002)

`GET /portal/api/inbox/{register}/{schema}/{id}` MUST, before any read, require a portal session, match a `kind: inbox` collection in the subject's own contributions, re-check that collection's `minTrust`, and resolve the scope value from its `scopeClaim`. It MUST read the object with the scope and the organisation as filters. A message of another resident, of another organisation, or that does not exist MUST get the same 404. The endpoint MUST NOT write.

#### Scenario: Someone else's message is not found
- GIVEN a message addressed to Henk
- WHEN Sanne's session asks for its id
- THEN the answer is 404, the same as for an id that does not exist, and no row is read without Sanne's scope

#### Scenario: A collection outside the resident's contributions
- GIVEN a register and schema that are no inbox collection in Sanne's contributions
- WHEN her session asks for a message there
- THEN the answer is 404 and nothing is read

#### Scenario: Too low a trust level
- GIVEN an inbox collection with `minTrust: substantial` and a session at `low`
- WHEN that session asks for one of its messages
- THEN the answer is 403 and nothing is read

#### Scenario: No session
- GIVEN no portal session
- WHEN the endpoint is called
- THEN the answer is 401

#### Scenario: The page says nothing about whose message it was
- GIVEN the endpoint answered 404
- WHEN the message page renders
- THEN it reads "Dit bericht bestaat niet of is niet voor u." with the back link

### Requirement: Opening an unread message MUST mark it read (REQ-ROM-003)

When the page shows an unread message, the site MUST mark it read through the existing mark-read endpoint, so the read receipt rules of REQ-IRR-002 apply unchanged. After the server confirms, the page MUST show the text badge "Gelezen" and the menu's unread count MUST drop by one. A message that is already read MUST NOT be marked again.

#### Scenario: The first open
- GIVEN Sanne has three unread messages and opens one of them
- WHEN the page has loaded
- THEN the message reads "Gelezen" and the menu shows "Berichten 2"

#### Scenario: A message that asks for a receipt
- GIVEN a message with `readReceiptRequested: true`, not read yet
- WHEN Sanne opens its page at 09.14
- THEN `readAt` holds 09.14, and opening it again at 11.02 leaves `readAt` at 09.14

### Requirement: The page MUST show the message's details, text and record as drawn (REQ-ROM-004)

The page MUST show a block "Over dit bericht" with Van, Over zaak and Ontvangen, and with Aard, Rechtsgevolg and Termijn when the message carries them. A row without a value MUST be left out. It MUST show the message text under "Bericht". When the message has a record link, the page MUST show the record as a card with "Naar uw zaak", and "Naar uw zaak" again below the message, both leading to the record's page.

#### Scenario: A decision with its formal fields
- GIVEN the Woo decision carries aard "Besluit op uw Woo-verzoek", rechtsgevolg "Dit is een besluit van de gemeente. Het geldt vanaf de dag van publicatie." and termijn "Bezwaar maken kan tot en met 18 november 2026"
- WHEN Sanne opens it
- THEN "Over dit bericht" lists Van, Over zaak, Ontvangen, Aard, Rechtsgevolg and Termijn with those values

#### Scenario: A message without formal fields
- GIVEN a message with no aard, rechtsgevolg or termijn
- WHEN it is opened
- THEN "Over dit bericht" lists Van, Over zaak and Ontvangen only

#### Scenario: To the case
- GIVEN the message links to case 2026-0082, "Verlichting fietspad Lindelaan"
- WHEN Sanne chooses "Naar uw zaak"
- THEN the case page of 2026-0082 opens

#### Scenario: A message about no record
- GIVEN a message with no record link
- WHEN it is opened
- THEN no case card and no "Naar uw zaak" show

### Requirement: Files that came with the message MUST open from its page (REQ-ROM-005)

For a collection that declares `filesDownload`, the page MUST list each attached file under "Bijlagen" with its name, type and size, and MUST download it through the scoped download of REQ-IRA-004. A collection that does not declare it MUST show no "Bijlagen".

#### Scenario: Two attachments
- GIVEN the Woo decision carries "Besluit Woo-verzoek 2026-0082.pdf" (412 kB) and "Inventarislijst.pdf" (86 kB)
- WHEN Sanne opens the message
- THEN "Bijlagen" lists "Besluit Woo-verzoek 2026-0082.pdf, PDF · 412 kB" and "Inventarislijst.pdf, PDF · 86 kB"
- AND choosing the first downloads it

#### Scenario: A collection without downloads
- GIVEN a message from a collection that does not declare `filesDownload`
- WHEN it is opened
- THEN no "Bijlagen" block shows

### Requirement: The page MUST offer a reply and an action only when the collection declares them (REQ-ROM-006)

The page MUST show the reply form of REQ-IRA-001 below the message when the collection declares a reply, and the one action button of `actionField` when the message carries a same-portal action. Without either declaration, the page MUST show neither.

#### Scenario: A decision without a reply
- GIVEN the Woo decision comes from a collection that declares no reply and carries no action
- WHEN Sanne opens it
- THEN the page shows no reply form and no action button, as the board draws it

#### Scenario: A handler's question
- GIVEN a handler's message from a collection that declares its reply action
- WHEN the resident opens it
- THEN the reply form shows below the message, and a sent reply lands on the message's case
