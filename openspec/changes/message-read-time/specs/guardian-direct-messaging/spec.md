## ADDED Requirements

### Requirement: A message records when the other side first read it

When a participant other than a message's sender marks the thread read and the message has no `readAt`, the message MUST get `readAt`, the current moment, together with the reader in `readBy`. `readAt` MUST NOT change after that, and the sender's own read MUST NOT set it.

#### Scenario: The teacher opens the guardian's message
- GIVEN Fatima's message to juf Esra, read by Fatima only
- WHEN juf Esra opens the conversation
- THEN the message's `readBy` holds Fatima and juf Esra, and `readAt` is the moment she opened it
- AND a second teacher opening it later leaves `readAt` as it was
- @e2e exclude server write, covered by PHPUnit `InAppMessagingLeafTest::testTheFirstReadByTheOtherSideRecordsWhen`

### Requirement: The sender sees when her message was read

On the messages page, a message the resident sent MUST show "Gelezen door {name} op {date}, {time}" under it once it has `readAt`, with the name of the person she wrote to, and MUST show nothing while it has none.

#### Scenario: Read by juf Esra
- GIVEN Fatima's message to juf Esra with `readAt` 5 October 2026 10:12
- WHEN Fatima opens the conversation
- THEN her message reads "Gelezen door Juf Esra op 5 oktober 2026, 10:12" under it
- @e2e exclude pure function, covered by `node --test tests/site-messages-per-record.spec.mjs` ("her own message says when the other side read it")
