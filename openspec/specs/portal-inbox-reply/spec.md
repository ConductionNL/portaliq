# portal-inbox-reply Specification

## Purpose
A resident answers a message from the organisation from their portal inbox, adds files to the answer, and opens the files that came with a message. The answer lands in the case app's own message store, linked to the case the message was about. Closes portaliq matrix rows `cmp-inb-reply` and `cmp-inb-attach`.

## Requirements

### Requirement: An inbox collection declares its reply (REQ-IRA-001)

A `kind: inbox` collection MAY declare `reply` naming one of the same contribution's create actions, the fields carried from the message into the reply, and the field that prefills the subject. Portaliq SHALL drop a declaration whose action is not such a create action, and SHALL keep only carried fields the action whitelists and the collection projects. A message from a collection without a reply declaration SHALL show no reply control.

#### Scenario: A message the case app lets you answer
- **GIVEN** a case app whose inbox collection declares its reply action
- **WHEN** a resident opens their inbox
- **THEN** each message from that collection shows a "Reply" button

#### Scenario: A receipt cannot be answered
- **GIVEN** a receipt message in portaliq's own inbox collection, which declares no reply
- **WHEN** the resident opens their inbox
- **THEN** the receipt shows no "Reply" button

### Requirement: The reply is built on the server from the resident's own message (REQ-IRA-002)

`POST /portal/api/inbox/{register}/{schema}/{id}/reply` SHALL prove the message is the resident's with the same checks as marking it read, SHALL set every carried field from the original message and ignore the client's value for it, and SHALL write the reply through the same pipeline as any portal create: defaults, scope stamping, `minTrust` and the cross reference guard. A message that is not the resident's SHALL get the same 404 as one that does not exist.

#### Scenario: The reply lands on the right case
- **GIVEN** a handler's message about case Z/2026/09128 in a resident's inbox
- **WHEN** the resident replies to it
- **THEN** the case app holds a new message from the resident linked to case Z/2026/09128

#### Scenario: A tampered case reference is overwritten
- **GIVEN** a reply request whose body names another case
- **WHEN** the server builds the reply
- **THEN** the reply carries the case of the original message

#### Scenario: Someone else's message cannot be answered
- **GIVEN** a message addressed to another resident
- **WHEN** a resident posts a reply to its id
- **THEN** the portal answers 404 and nothing is written

### Requirement: Files go with the reply (REQ-IRA-003)

The reply form SHALL offer the reply action's declared file fields, honouring their accepted types and size limit, and SHALL upload the files into the reply after it is written. When a file fails after the reply was written, the portal SHALL keep the reply and SHALL say which file was not added.

#### Scenario: A resident sends a photo with the answer
- **GIVEN** a reply action whose `attachments` field is a declared file field accepting images
- **WHEN** the resident writes an answer, adds a photo and presses "Send"
- **THEN** the reply is written, the photo is attached to it, and the form says "Your reply has been sent."

#### Scenario: A file fails after the text arrived
- **GIVEN** a reply with two files, the second of which the server refuses
- **WHEN** the resident sends it
- **THEN** the reply and the first file exist, and the form names the second file as not added

### Requirement: Files that came with a message open (REQ-IRA-004)

For an inbox collection that declares `filesDownload`, the inbox SHALL list each message's attached files and SHALL let the resident download them through the scoped download. A collection that does not declare it SHALL list no files.

#### Scenario: A handler's attachment opens
- **GIVEN** a handler's message with an attached letter, in a collection that declares `filesDownload`
- **WHEN** the resident opens their inbox and presses the letter
- **THEN** the letter downloads
