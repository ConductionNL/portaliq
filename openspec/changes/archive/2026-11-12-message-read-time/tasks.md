# Tasks: message-read-time

- [x] **T1** `InAppMessagingLeaf::markOneMessageRead` stamps `readAt` on the first read by someone other than the sender (`withReadAt`). Test: `InAppMessagingLeafTest::testTheFirstReadByTheOtherSideRecordsWhen`.
- [x] **T2** register 0.106.0, guardianMessage 0.3.0 `readAt`. Test: `PortaliqRegisterConfigTest`.
- [x] **T3** site: `readLine()` and the line under her own message on MessagesPage. Test: `tests/site-messages-per-record.spec.mjs`.
