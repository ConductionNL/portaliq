# Tasks: citizen-case-write-limit

- [ ] **T01**: `tests/Unit/Service/CitizenWriteThrottleTest.php`: a refused write is counted, so the next write after the limit is still refused (REQ-CWL-001). Verification: the test fails when `allow()` stops counting refused writes.
- [ ] **T02**: `tests/Unit/Controller/CitizenCaseControllerTest.php`: past the limit a withdrawal and an added document answer 429 `too-many-writes`, and the case writer is never called (REQ-CWL-001).
- [ ] **T03**: Point the `@spec` tags on `CitizenWriteThrottle::allow()` and `CitizenCaseController::guardWrite()` at REQ-CWL-001 (the tag on `allow()` names a change that has since been archived).
- [ ] **T04**: `openspec validate citizen-case-write-limit --strict`.
