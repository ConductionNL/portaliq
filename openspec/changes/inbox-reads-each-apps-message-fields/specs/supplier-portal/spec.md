## ADDED Requirements

### Requirement: An inbox collection names its own message fields (REQ-IMF-001)

A `kind: inbox` collection MAY declare `messageFields`, a map from the inbox's fields (`subject`, `body`, `receivedAt`, `readAt`, `attachments`) to plain field names of that collection. Portaliq SHALL copy each named field onto the inbox row it serves, under the inbox's own name, before it sorts the inbox and counts unread messages. With `readAt` named, the row SHALL be read when that field holds a value. A malformed entry SHALL be dropped, and on a collection that is not an inbox the whole key SHALL be dropped.

#### Scenario: A dossiq message shows its text and date
- **GIVEN** an inbox collection declaring `messageFields: {body: content, receivedAt: sentAt, readAt: readByRecipientAt}`
- **AND** a message with `content`, `sentAt` and no `readByRecipientAt`
- **WHEN** the resident reads their inbox
- **THEN** the row carries `body` and `receivedAt` from those fields, sorts by that date, and is unread

#### Scenario: A malformed declaration is dropped
- **GIVEN** a collection declaring `messageFields` with a value that is not a plain field name
- **WHEN** portaliq normalises the contribution
- **THEN** that entry is gone, and a collection that is not an inbox loses the whole key

### Requirement: Mark-read writes the collection's own read field (REQ-IMF-002)

When an inbox collection names `readAt`, mark-read SHALL write the current time into that field and no other field. When it does not, mark-read SHALL write `read: true` as before. Nothing from the request body SHALL be written.

#### Scenario: A resident marks a dossiq message read
- **GIVEN** an inbox collection naming `readAt: readByRecipientAt`
- **WHEN** the resident marks one of their messages read
- **THEN** only `readByRecipientAt` is written, with the current time, and the message reads as read on the next load
