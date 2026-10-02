# Tasks: citizen-case-ended-shows-only-its-state

- [x] **T1**: `CitizenWritableSetResolver::resolve()` and `::withdrawal()` take the collection's `closedField`; `hasEnded()` reads `withdrawnAt` and the marker; an ended set closes every window with the neutral sentence and carries `ended`
  - PHPUnit `CitizenWritableSetResolverTest::testAWithdrawnCaseHasEndedAndInvitesNothing`, `::testACaseTheCollectionMarksClosedHasEnded`, `::testARunningCaseKeepsTheCaseTypesSentences`, `CitizenWithdrawalResolutionTest::testAClosedCaseCannotBeWithdrawn`
  - Mutation: ignoring `ended` in `resolve()` (3 failures), ignoring `withdrawnAt` (1), ignoring it in `withdrawal()` (2)
- [x] **T2**: `CitizenWriteActionFinder` hands over the collection's `closedField`; `CitizenCaseController` passes it to the resolver on read, amendment, document and withdrawal
  - PHPUnit `CitizenCaseControllerTest::testACaseItsCollectionMarksClosedHasEnded`
  - Mutation: the finder answering '' or the controller passing '' fails it
- [x] **T3**: the case screen hides every closed-window sentence on an ended case (`caseHasEnded(writableSet, view)`), and `CaseField` takes `quiet`
  - `node --test tests/case-withdraw-screen.spec.mjs` (`npm run check:case-withdraw-screen`)
  - Mutation: dropping any of the four guards, or the withdrawn fallback, fails a test
