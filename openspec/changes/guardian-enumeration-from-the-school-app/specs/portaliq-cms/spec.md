## ADDED Requirements

### Requirement: Newsletter and emergency recipients come from the school app

When the newsletter preflight, the newsletter send check or the emergency push resolve which guardians a target reaches, the result MUST include every active portal account of the `parent` audience whose audience, read from the school app through `LeafGuardianAudienceReader`, matches the target by the same rule the news feed uses. Guardians with an interim `guardianAudienceFixture` row MUST still be matched on that row, and MUST NOT be resolved a second time through the school app. Pending and void accounts MUST NOT be counted.

#### Scenario: The preflight counts a real guardian of the group
- GIVEN learniq declares `guardianAudience` and a guardian with an active portal account has a child in group 7
- AND no fixture row exists for that guardian
- WHEN staff runs the preflight for a newsletter targeted at group 7
- THEN the recipient count includes that guardian
- @e2e exclude backend enumeration contract, pinned by `GuardianAudienceFixtureReaderTest::testGuardiansMatchingAddsTheGuardiansTheSchoolAppResolves`; no staff screen shows the preflight in this change

#### Scenario: An emergency push reaches a real guardian of the school
- GIVEN the same guardian
- WHEN staff sends an emergency push targeted at the child's school
- THEN the push is delivered to that guardian and counted in `recipientCount`
- @e2e exclude the push controller only forwards `guardiansMatching()`, pinned by `EmergencyPushControllerTest`; the enumeration by `GuardianAudienceFixtureReaderTest`

#### Scenario: A fixture guardian is matched on the fixture only
- GIVEN a guardian with a fixture row in group 5 whose school app audience says group 7
- WHEN staff targets group 7
- THEN that guardian is not counted
- @e2e exclude precedence rule, pinned by `GuardianAudienceFixtureReaderTest::testAFixtureRowIsNeverResolvedAgainThroughTheSchoolApp`
