# Tasks: claim-scoped-create-stamps-the-claim

- [x] **T1**: The create path stamps the scope field with the resolved `scopeClaim`, and refuses without it
  - PHPUnit `ContributionControllerTest::testAClaimScopedCreateStampsTheClaim`, `::testAClaimScopedCreateWithoutTheClaimIsRefused`
  - Live: learniq po-parent-flows e2e, a guardian reports their child absent from the portal
