# Tasks: citizen-case-shows-only-its-fields

- [x] **T1**: `CitizenCaseController` projects the case it returns from `show`, `amend` and `withdraw` through `PortalFieldProjector`; `CitizenWriteActionFinder` hands over the collection's `fields`
  - PHPUnit `CitizenCaseControllerTest::testTheCaseScreenReceivesOnlyTheDeclaredFields`, `::testAWithdrawnCaseComesBackWithoutTheStaffFields`, `::testAnAmendedCaseComesBackWithoutTheStaffFields`, `::testAMalformedDeclarationShowsOnlyTheIdentifiers`, `::testFieldsDeclaredOnAnotherSchemaDoNotApply`
  - Mutation: returning the row unprojected fails four of them
- [x] **T2**: the case screen lists only the writable set's answers (`caseFieldNames(caseRow, writableSet)`)
  - `node --test tests/case-withdraw-screen.spec.mjs` (`npm run check:case-withdraw-screen`)
- [x] **T3**: `statusLabelField` on a `cases` collection: normalised like `closedField`, stamped as `_statusLabel` on own and mandated rows, shown by `caseStatus(row)` on "Mijn zaken"
  - PHPUnit `PortalManifestNormaliserTest::testAStatusLabelFieldIsKeptOnlyWhenItNamesAProjectedField`, `PortalCaseListReaderTest::testEachRowCarriesTheStatusWordsTheCollectionNames`
  - `node --test tests/my-cases-page.spec.mjs` (`npm run check:my-cases-page`)
  - Mutation: dropping either stamping call or the normaliser call fails a test
- [x] **T4**: nl, en and en_US entries for the three withdrawal sentences; `npm run check:l10n-js`
- [x] **T5**: a `citizenCase` block under a `detail` block on the same collection is `quietWhenEmpty`
  - `node --test tests/site-collections.spec.mjs` (`npm run check:site-collections`)
