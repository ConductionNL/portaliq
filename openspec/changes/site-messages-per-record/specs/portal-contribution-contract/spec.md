## ADDED Requirements

### Requirement: A collection may name who a resident may write to about each row

A collection MAY declare `contacts: {provider, recordLabelFields?, composeLabel?, composeHint?}`. `provider` MUST pass the rule of a timeline provider (a lower-camel method name that is not a contract method), or the whole key MUST be dropped. `recordLabelFields` MUST keep only fields the collection projects, at most three. `composeLabel` and `composeHint` are authored words of at most 200 characters. A provider's answer for one row MUST be held to `{staffRef, name, role}`: an entry without a person or a name MUST be dropped, a person named twice counts once, at most 30 per row.

#### Scenario: A school names the teacher of each child's group
- GIVEN a parent collection over enrolments with `contacts: {provider: messageContactsFor, recordLabelFields: [learnerName, cohortName]}`
- WHEN the manifest is normalised
- THEN the key keeps its provider and the two label fields

#### Scenario: A contract method is never a provider
- GIVEN `contacts: {provider: getContribution}`
- WHEN the manifest is normalised
- THEN the collection has no `contacts`

### Requirement: A resident may start a conversation only with a contact of their own record

`GET /api/messages/contacts` MUST answer, for the signed-in resident, every contact the declaring collections name for each row the resident owns (read through the same scoped reader as the collection, and only when the session meets the collection's `minTrust`), each with `recordRef` and `recordLabel`. `POST /api/messages/threads` with a `recordRef` MUST read that record again through the scoped reader and ask the provider again; it MUST refuse with 403 when the record is not the resident's or the provider does not name that `staffRef` for it, and the provider MUST NOT be asked about a record the resident does not own. An empty message or one over 5000 characters, or a subject over 120, MUST be refused with 400 before anything is asked. On success the thread MUST carry the record, its words, the subject (the start of the message when none is given) and the contact's name and role, and MUST start with the resident's message, read by the resident.

#### Scenario: Fatima writes to Meester Daan about Vera
- GIVEN Vera's enrolment is Fatima's and the school names Meester Daan for it
- WHEN Fatima posts a message to Meester Daan about that enrolment
- THEN a thread "over Vera" with Meester Daan exists and holds her message

#### Scenario: A teacher of another child is refused
- GIVEN the school names Juf Esra for Sami's enrolment only
- WHEN Fatima posts to Juf Esra about Vera's enrolment
- THEN the answer is 403 and nothing is stored

### Requirement: The messages page groups conversations per record and lets a resident write and reply

The site's messages page MUST show a tab "Alle berichten" and one tab "Over {name}" per record that has a conversation or a contact, when there are at least two; a tab shows that record's conversations, newest first. Each conversation MUST be a card with the staff member's name (else "School"), when, "over {name}", the subject, the start of the newest message and a "Nieuw" badge while the reader has unread messages in it; its button MUST open the messages and a labelled reply form, and opening MUST mark it read. When the resident has contacts, the page MUST show a "Nieuw bericht" button that moves to the form, and the form with a labelled "Aan" field ("Meester Daan, over Vera (Groep 7)"), an optional subject and the message, under the declared `composeLabel` and `composeHint`. A sent or refused message MUST say so in words; a refused one stays in the form. With no contacts there is no form.

#### Scenario: Two children, two tabs
- GIVEN Fatima has a conversation about Vera and one about Sami
- WHEN she opens Berichten
- THEN she sees "Alle berichten", "Over Vera" and "Over Sami", and the conversation about Sami first with "Nieuw"

#### Scenario: A reply in an open conversation
- GIVEN Fatima opened the conversation with Juf Esra
- WHEN she writes "Dank u wel!" and sends it
- THEN the reply is posted to that conversation and the form is empty again
