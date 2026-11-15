# Tasks: mandated-case-opens-on-its-record-page

- [x] **T1**: `pickedRecord()` in `src/site/pages/collections/collectionLoader.js`; `ContributionPage.vue`'s `activeRecord` opens through it, and a route-chosen record keeps the kept link's row
  - node `tests/collection-loader.spec.mjs` "a record page opens a row picked under a mandate, and nothing else outside the list"
- [x] **T2**: `PortalTimelineController` proves a case under the named `mandate` through `MandatedCaseReader`; `fetchSteps` and `fetchTimeline` send it, and the steps, timeline and documents blocks pass the open record's mandate
  - PHPUnit `PortalTimelineControllerTest::testACaseSeenUnderAMandateIsProvenByTheMandatedReader`; node `tests/mijn-cases.spec.mjs` "a case read under a mandate asks its steps under that mandate"
- [ ] **T3**: Live check on the rehearsal: Linda opens Henk's case from "Mijn zaken" and reads it
