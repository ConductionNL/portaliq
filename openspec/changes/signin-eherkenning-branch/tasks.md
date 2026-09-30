# Tasks: signin-eherkenning-branch

## Claim and session

- [x] **T01**: `claimMap.branch` in `OidcClaimMapperService`, a 12-digit check, the value handed to the callback (REQ-SEB-001)
  - Verify: PHPUnit `OidcClaimMapperServiceTest`: a valid branch, a malformed one dropped, none configured
- [x] **T02**: `branch` and `branchRestricted` signed by `issueSession()` and carried by `resolveFromBearer()` and `refreshSession()` (REQ-SEB-001)
  - Verify: PHPUnit `PortalSessionServiceTest` round trip, and a refresh keeps the restriction

## Scoping

- [x] **T03**: `branchField` in `CollectionConfigNormaliser`, kept only when it names a projected field (REQ-SEB-002)
  - Verify: PHPUnit normaliser test
- [x] **T04**: `PortalObjectReader` filtering of design D2, including the fail-closed answer for a restricted session and a collection without `branchField`; the writer stamps `branchField` (REQ-SEB-002)
  - Verify: PHPUnit for the three session kinds; `hydra-gate-no-admin-idor` green

## Choice

- [ ] **T05** (blocked: needs the company's KvK branch list, which `identity-registered-details` T02 reads through OpenRegister's `KvkProvider`; not built yet): `POST /portal/api/session/branch`, refused for a restricted session and for a foreign branch (REQ-SEB-003)
  - Verify: PHPUnit controller test with the KvK lookup mocked
- [x] **T06a**: The branch in effect shown in the header (REQ-SEB-001)
  - Verify: `tests/branch-in-effect.spec.mjs` (`branchInEffect()` in `src/portal/lib/branch.js`, both locales, the header wiring in `App.jsx`)
- [ ] **T06**: The branches in the header's "Acting for" (REQ-SEB-003). Blocked with T05 on the KvK branch list of `identity-registered-details`.
  - Verify: Playwright `tests/e2e/signin-eherkenning-branch.spec.ts` with the OIDC broker stubbed to send a branch claim, and once without

## Close

- [x] **T07a**: Dutch and English strings for the header; admin docs `docs/operations/acting-for-one-branch.md`; the dossiq `branchField` half and the integriq envelope half drafted for Ruben (`~/memcap-work/build-all/for-ruben/dossiq-branch-field-on-business-cases.md`, `integriq-broker-envelope-carries-the-branch.md`)
- [ ] **T07**: strings for the branch choice (with T05/T06); `openspec validate signin-eherkenning-branch --strict`
