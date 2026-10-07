## ADDED Requirements

### Requirement: A claim-scoped create stamps the claim

When a `create` action declares `scopeClaim`, the writer MUST stamp the action's `scopeField` with the claim value resolved server side from the subject's own `portalAccount`, over any client value, exactly as the read path resolves it. When the claim is absent the create MUST be refused with 403 and nothing written. Without `scopeClaim` the stamp MUST remain the subject's `subjectRef`.

#### Scenario: A guardian's absence report carries the guardian's learniq reference

- GIVEN a guardian whose portal account carries `claims.learniq.guardianRef`
- AND learniq's action `createExcuseRequest` with `scopeField: submittedByRef` and `scopeClaim: guardianRef`
- WHEN the guardian reports their child absent
- THEN the stored report's `submittedByRef` is the guardian's learniq reference
- @e2e learniq `tests/e2e/po-parent-flows.spec.ts`

#### Scenario: No claim, no write

- GIVEN a subject whose portal account lacks the declared claim
- WHEN they submit the create action
- THEN the answer is 403 and nothing is written
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testAClaimScopedCreateWithoutTheClaimIsRefused`
