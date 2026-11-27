# Tasks: anonymous-collection-reads-what-the-public-may

- [x] **T1**: `PortalObjectReader::readPublicCollection()` reads with RBAC on, narrows by `filter`, drops another tenant's rows, projects
  - PHPUnit `PortalObjectReaderTest::testAPublicReadLetsOpenRegisterDecideAndNarrowsAndProjects`
- [x] **T2**: `ContributionController::readRows()` reads an `anonymous` collection through it
  - PHPUnit `ContributionControllerTest::testAnAnonymousCollectionIsReadAsThePublicMayReadIt`
- [x] **T3**: decidiq narrows its calendar and its motions with `filter` (decidiq PR)
  - done on decidiq `development`: `lib/Portal/PortalContributionProvider.php` declares `publicCalendar` with `filter: {oriType: Vergadering}` and `motionsOpenForAdvice` with `filter: {citizenVotingAllowed: true}`
- [x] **T4**: Live check on the rehearsal: "Vergaderkalender" lists the council meetings
  - :8080 (portaliq 7078a7b), 31 Oct, signed in as Sanne: Vergaderkalender lists three council meetings (8 Oct, 22 Oct, 5 Nov 2026). T3 was still open then.
