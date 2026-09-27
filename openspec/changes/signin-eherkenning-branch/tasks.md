# Tasks: signin-eherkenning-branch

## Claim and session

- [ ] **T01**: `claimMap.branch` in `OidcClaimMapperService`, a 12-digit check, the value handed to the callback (REQ-SEB-001)
  - Verify: PHPUnit `OidcClaimMapperServiceTest`: a valid branch, a malformed one dropped, none configured
- [ ] **T02**: `branch` and `branchRestricted` signed by `issueSession()` and carried by `resolveFromBearer()` and `refreshSession()` (REQ-SEB-001)
  - Verify: PHPUnit `PortalSessionServiceTest` round trip, and a refresh keeps the restriction

## Scoping

- [ ] **T03**: `branchField` in `CollectionConfigNormaliser`, kept only when it names a projected field (REQ-SEB-002)
  - Verify: PHPUnit normaliser test
- [ ] **T04**: `PortalObjectReader` filtering of design D2, including the fail-closed answer for a restricted session and a collection without `branchField`; the writer stamps `branchField` (REQ-SEB-002)
  - Verify: PHPUnit for the three session kinds; `hydra-gate-no-admin-idor` green

## Choice

- [ ] **T05**: `POST /portal/api/session/branch`, refused for a restricted session and for a foreign branch (REQ-SEB-003)
  - Verify: PHPUnit controller test with the KvK lookup mocked
- [ ] **T06**: The branches in the header's "Acting for" and the branch in effect shown in the header (REQ-SEB-003)
  - Verify: Playwright `tests/e2e/signin-eherkenning-branch.spec.ts` with the OIDC broker stubbed to send a branch claim, and once without

## Close

- [ ] **T07**: Dutch and English strings; admin docs on the `claimMap.branch` setting; hand the `branchField` half to the dossiq lane; `openspec validate signin-eherkenning-branch --strict`
