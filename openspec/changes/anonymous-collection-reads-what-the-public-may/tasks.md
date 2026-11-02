# Tasks: anonymous-collection-reads-what-the-public-may

- [x] **T1**: `PortalObjectReader::readPublicCollection()` reads with RBAC on, narrows by `filter`, drops another tenant's rows, projects
  - PHPUnit `PortalObjectReaderTest::testAPublicReadLetsOpenRegisterDecideAndNarrowsAndProjects`
- [x] **T2**: `ContributionController::readRows()` reads an `anonymous` collection through it
  - PHPUnit `ContributionControllerTest::testAnAnonymousCollectionIsReadAsThePublicMayReadIt`
- [ ] **T3**: decidiq narrows its calendar and its motions with `filter` (decidiq PR)
- [ ] **T4**: Live check on the rehearsal: "Vergaderkalender" lists the council meetings
