# Tasks: via-read-scoped-query

- [x] **T1**: The via read asks OpenRegister for the subject's own rows and applies the declared filter (REQ: One-hop via join scoping)
  - PHPUnit `PortalObjectReaderViaQueryTest::testReverseViaFindsTheChildsRowsBeyondThePageOfOtherRows`, `::testReverseViaAppliesTheDeclaredFilter`, `::testADeclaredFilterOnTheScopeFieldCannotWidenTheVia`
  - Live: learniq `po` example set on a clean instance, a guardian reads `parentAttendance` and `parentReportCards` and sees the child's rows
