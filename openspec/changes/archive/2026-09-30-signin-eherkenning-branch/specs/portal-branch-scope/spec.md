---
status: proposed
---

# Spec: portal-branch-scope

**Status:** proposed
**Scope:** portaliq (owner); a case app declares the branch field of its business cases
**Depends on:** `2026-07-24-portal-oidc-broker-login`, `supplier-portal`, `cases-my-cases-page`, `identity-registered-details`

## Purpose

A business user who signed in with eHerkenning for one branch sees that
branch's cases only, and a user who signed in for the whole company can narrow
to one branch. Requested by the portaliq parity matrix row
`dem-cl-eherkenning-branch`.

## ADDED Requirements

### Requirement: The login's branch travels with the session (REQ-SEB-001)

When the organisation maps a branch claim and the broker's eHerkenning login
carries a valid vestigingsnummer, the portal SHALL put that branch on the
session, marked as restricted, and SHALL keep it across refreshes. A malformed
value SHALL be dropped and SHALL NOT widen or narrow the session.

#### Scenario: A shop manager signs in for one branch
@e2e exclude The CI instance has no eHerkenning broker to send a branch claim; tests/Unit/Service/OidcClaimMapperServiceTest.php, tests/Unit/Service/PortalSessionServiceTest.php::testALoginBranchTravelsWithTheSessionAsRestricted and tests/branch-in-effect.spec.mjs cover the claim, the session and the header.
- **GIVEN** an organisation whose eHerkenning `claimMap.branch` names the broker's vestigingsnummer claim
- **WHEN** a manager signs in with eHerkenning restricted to branch 000012345678
- **THEN** the portal header reads that they act for branch 000012345678

### Requirement: A restricted session sees only its branch (REQ-SEB-002)

For a session restricted to a branch, the portal SHALL list only the rows of a
collection whose declared branch field equals that branch, and SHALL show
nothing of a collection that declares no branch field. A single case of another
branch SHALL answer as not found. A case filed in such a session SHALL carry
the branch.

#### Scenario: Another branch's case stays hidden
@e2e exclude Needs a restricted session, which only a broker login mints; tests/Unit/Service/PortalCaseListReaderTest.php and tests/Unit/Controller/ContributionControllerTest.php assert the filter over the real scope.
- **GIVEN** a session restricted to branch 000012345678 and a collection declaring `branchField`
- **WHEN** the manager opens "My cases"
- **THEN** only the cases of branch 000012345678 are listed

#### Scenario: An app without a branch field shows nothing to a restricted session
@e2e exclude Same reason; tests/Unit/Controller/ContributionControllerTest.php asserts the fail-closed answer.
- **GIVEN** a session restricted to a branch and a case collection that declares no `branchField`
- **WHEN** the manager opens the page of that collection
- **THEN** it lists no cases

### Requirement: A whole-company user can narrow to a branch (REQ-SEB-003)

A business user whose login carried no branch SHALL be able to choose one of
the company's branches, or the whole company, under "Acting for". The choice
SHALL filter the collections that declare a branch field and SHALL be refused
for a branch that is not the company's.

#### Scenario: Narrowing to one branch
@e2e exclude The CI instance configures no kvk source, so the company has no branch to choose; tests/e2e/signin-eherkenning-branch.spec.ts drives the refusal and the whole-company choice, and tests/Unit/Service/Branch/BranchChoiceTest.php, tests/Unit/Service/PortalSessionServiceTest.php::testAWholeCompanySessionNarrowsToABranchAndBack and tests/branch-choice.spec.mjs cover narrowing and back.
- **GIVEN** a business user signed in for the whole company, whose company has two branches
- **WHEN** they choose the Utrecht branch under "Acting for"
- **THEN** "My cases" lists the Utrecht branch's cases, and choosing "Whole company" lists all again

#### Scenario: A restricted session cannot widen
@e2e exclude Needs a restricted session, which only a broker login mints; tests/Unit/Service/PortalSessionServiceTest.php::testARestrictedSessionCannotChooseABranch and tests/Unit/Service/Branch/BranchChoiceTest.php::testARestrictedSessionIsOfferedNothingAndAllowedNothing cover it.
- **GIVEN** a session restricted to branch 000012345678
- **WHEN** the browser posts another branch to `POST /portal/api/session/branch`
- **THEN** the answer is refused and the session keeps its branch
