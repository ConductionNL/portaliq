## ADDED Requirements

### Requirement: A resident's writes on her own case are limited per case and per identity (REQ-CWL-001)

The portal SHALL count every amendment, added document and withdrawal a portal identity sends on its own case, per case and per identity, in a window of one hour. A write SHALL be refused when it is the 21st on one case or the 61st for one identity within that window. A refused write SHALL count as well. The refusal SHALL answer 429 with the error `too-many-writes` and the sentence "You have made a lot of changes in a short time. Please try again later." in the portal's language, and SHALL change nothing on the case. A write without a subject reference SHALL be refused. When no counter store is available, the guard SHALL let the write through and the framework's own route limits SHALL remain the floor.

#### Scenario: The 21st correction in an hour
- **GIVEN** a resident who sent 20 corrections on case 2026-0082 in the last hour
- **WHEN** she sends one more
- **THEN** the answer is 429 with `too-many-writes` and the case is unchanged
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testOneCaseIsStoppedAtThePerCaseLimit and CitizenCaseControllerTest::testPastTheThrottleTheWriteIsRefused

#### Scenario: Another case keeps its own budget
- **GIVEN** the same resident at the limit on case 2026-0082
- **WHEN** she adds a document to case 2026-0061
- **THEN** the document is added
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testASecondCaseHasItsOwnBudget

#### Scenario: A withdrawal counts as a write
- **GIVEN** a resident at the limit on a case
- **WHEN** she withdraws that case
- **THEN** the answer is 429 and the case is not withdrawn
- @e2e exclude Server-side guard; pinned by CitizenCaseControllerTest
