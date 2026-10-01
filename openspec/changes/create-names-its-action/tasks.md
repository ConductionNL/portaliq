# Tasks: create-names-its-action

- [x] **T1**: The create path matches the action by its id, refuses an unknown id, and refuses an ambiguous create without one
  - PHPUnit `ContributionControllerTest::testCreateWritesThroughTheActionItNames`, `::testCreateNamingAnUnknownActionIsRefused`, `::testCreateWithoutAnIdBetweenTwoActionsIsRefusedNotGuessed`, `::testCreateWithoutAnIdAndOneActionStillWorks`
- [x] **T2**: The portal frontend sends the action id on every create
  - `node --test tests/create-names-its-action.spec.mjs` (`check:create-names-its-action`)
