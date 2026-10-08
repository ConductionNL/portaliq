# Tasks: guardian-enumeration-from-the-school-app

- [x] **T1**: `GuardianAccountDirectory` lists the active `parent` portal accounts
  - PHPUnit `GuardianAccountDirectoryTest`
- [x] **T2**: `GuardianAudienceFixtureReader::guardiansMatching()` adds the guardians the school app resolves, fixture rows first
  - PHPUnit `GuardianAudienceFixtureReaderTest::testGuardiansMatchingAddsTheGuardiansTheSchoolAppResolves`, `testAFixtureRowIsNeverResolvedAgainThroughTheSchoolApp`
  - Live: on the primary-school instance the newsletter preflight for Vera's group counts Fatima Hulstkamp, and the emergency push reports her as a recipient
