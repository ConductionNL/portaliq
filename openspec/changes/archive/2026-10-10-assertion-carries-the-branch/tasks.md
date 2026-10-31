# Tasks: the assertion carries the session's branch

- [x] 1. `lib/Service/PortalJwtService.php`: optional `branch` parameter and claim, `branch` reserved as a scope claim name. Test: `tests/Unit/Service/PortalJwtServiceTest.php` (`testABranchSessionAddsExactlyTheOptionalBranchClaim`, `testAnEmptyBranchAddsNothingAndBranchIsNoScopeClaim`; the nine-claim pin is unchanged).
- [x] 2. `lib/Service/PortalSessionService.php`: `issueAssertion()` passes the subject's branch. Test: `tests/Unit/Service/PortalSessionServiceTest.php::testAssertionCarriesTheSessionBranchOnlyWhenThereIsOne`.
- [x] 3. Spec: "Frozen assertion wire format" amended in `openspec/specs/portal-contribution-contract/spec.md`.
