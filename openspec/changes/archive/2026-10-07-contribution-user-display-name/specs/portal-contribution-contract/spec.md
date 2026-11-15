## ADDED Requirements

### Requirement: A column may show a Nextcloud user by name

A collection column MAY declare `"render": "user"`, meaning its value is a Nextcloud user id or a list of user ids. Portaliq MUST replace each such value with that user's display name on the server, in the collection list and in the single-object read, before the row is answered. A value that names no user on the instance, or that is not a user id, MUST be answered as `''`. The user id MUST NOT appear in the answer.

#### Scenario: A parent reads the teacher's name
- GIVEN learniq declares the column `{ "field": "handledBy", "render": "user" }` on a guardian's absence reports
- AND a report's `handledBy` is the user id `po-leerkracht-09`, whose display name is "Meester Jansen"
- WHEN the guardian opens the absence reports
- THEN the column reads "Meester Jansen" and the answer holds no `po-leerkracht-09`
- @e2e exclude pinned by `ContributionControllerUserNamesTest` (list and object answers); a browser sees only the name, which any text column also shows

#### Scenario: A value that names no user
- GIVEN a `render: "user"` value that names no user on the instance
- WHEN the row is answered
- THEN the value is `''`
- @e2e exclude pinned by `PortalUserDisplayNamesTest::testValuesBecomeNamesAndNothingElseLeaks`
