# Tasks: contribution-user-display-name

- [x] **T1**: `PortalUserDisplayNames` reads the `render: "user"` fields of a collection and replaces each user id with the display name, `''` when it names no user.
  - `PortalUserDisplayNamesTest` (3 tests)
- [x] **T2**: The collection list and the single object answer names (`ContributionController::collection`, `::object`).
  - `ContributionControllerUserNamesTest` (2 tests, asserts the user id is absent from the answer)
- [x] **T3**: The normaliser keeps `user` as a render kind.
  - `PortalManifestNormaliserTest::testAUserColumnKeepsItsRender`
