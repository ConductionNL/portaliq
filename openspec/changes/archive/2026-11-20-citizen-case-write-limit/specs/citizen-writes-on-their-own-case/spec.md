## ADDED Requirements

### Requirement: A resident's writes on her own case are limited per case and per identity (REQ-CWL-001)

The portal SHALL count every amendment, added document and withdrawal a portal identity sends on its own case, per case and per identity, in a window of one hour, refused writes included. It SHALL refuse the 21st write on one case and the 61st for one identity within that window with 429 `too-many-writes` and the sentence "You have made a lot of changes in a short time. Please try again later." in the portal's language, and change nothing on the case.

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

#### Scenario: A refused write counts too
- **GIVEN** a resident whose 21st correction on a case was refused
- **WHEN** she sends another one within the hour
- **THEN** it is refused as well
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testARefusedWriteIsCountedSoTheNextWriteIsStillRefused

#### Scenario: A write without an identity is refused
- **GIVEN** a write that carries no subject reference
- **WHEN** it reaches the guard
- **THEN** it is refused
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testAWriteWithNoIdentityIsRefused

#### Scenario: No counter store lets the write through
- **GIVEN** an instance without a distributed cache
- **WHEN** a resident sends a correction
- **THEN** the guard lets it through and the framework's own route limits remain the floor
- @e2e exclude Server-side guard; pinned by CitizenWriteThrottleTest::testNoCacheFailsOpenRatherThanRefusingEveryone
