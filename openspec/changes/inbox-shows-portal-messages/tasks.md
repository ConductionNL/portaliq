# Tasks: inbox-shows-portal-messages

- [x] **T01**: `PortalInboxReader`: read `portalMessage` as a built-in inbox source, deduplicated against declared collections (REQ-NAP-009). Verification: PHPUnit `PortalInboxReaderTest`.
- [x] **T02**: `ContributionController::markRead` accepts the built-in source (REQ-NAP-009). Verification: PHPUnit `ContributionControllerTest::testMarkReadReachesTheResidentsOwnPortalMessageWithoutADeclaredInbox`.
- [x] **T03**: The Woo e2e asserts the inbox API in J4, J5 and J6 (REQ-NAP-009). Verification: `tests/e2e/woo-journey.spec.ts`.
- [x] **T04**: `openspec validate inbox-shows-portal-messages --strict`.
