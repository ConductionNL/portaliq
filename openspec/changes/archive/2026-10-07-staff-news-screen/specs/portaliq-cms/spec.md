## ADDED Requirements

### Requirement: Staff write, change and publish news on a News screen

Portaliq's Nextcloud app MUST offer a News page where a signed-in staff member sees every news item with its audience and status, writes a new one, changes one and publishes it or takes it back. The audience MUST be chosen as the whole school or one or more groups. Every write MUST go through the staff authoring routes (`POST /api/news`, `PUT /api/news/{id}`, `PUT /api/news/{id}/publish`, `PUT /api/news/{id}/unpublish`), which keep `NewsController`'s staff guard; the screen MUST NOT write news through the object API.

#### Scenario: A teacher writes news for the whole school and publishes it
- GIVEN a teacher signed in to Nextcloud at a school whose school app declares `guardianAudience`
- WHEN they open News, write a title and text, choose the whole school and save
- THEN the item is listed as a draft for the whole school
- AND when they publish it, a guardian of that school reads it in the portal
- @e2e exclude live-checked on the primary-school instance (see the PR); the screen's calls and wiring are pinned by `tests/news-authoring.spec.mjs`, the routes by `NewsControllerTest`

#### Scenario: A change keeps everything the server owns
- GIVEN a published news item with read receipts
- WHEN staff change its text and audience
- THEN the title, text and audience change and the status, author and read receipts stay as they were
- @e2e exclude backend contract, pinned by `NewsControllerTest::testUpdateChangesTheTextAndAudienceOnly`

#### Scenario: A save without an audience is refused
- GIVEN staff choose "one or more groups" and pick none
- WHEN they save
- THEN the screen names what is missing and nothing is written
- @e2e exclude pinned by `tests/news-authoring.spec.mjs` ("a form names what is missing") and `NewsControllerTest`

### Requirement: The school and group choices come from the school app

`GET /api/news/audiences` MUST return the schools and groups a staff member can choose, each `{id, label}`. For every contribution that declares `guardianAudience`, the groups MUST come from `groups.options` (`{register?, schema}`) when declared, else from the `$ref` the groups field carries in its collection's schema; the schools likewise from `schoolOptions`, else from the `$ref` of `schoolField` on the children's schema. The option objects MUST be read as the signed-in staff member with OpenRegister's access rules on. When no source resolves, the list MUST be empty and the screen MUST ask for a reference instead.

#### Scenario: The groups follow the field reference
- GIVEN learniq's `enrolment.cohortId` carries `$ref: Cohort`
- WHEN a teacher opens the News dialog
- THEN the groups they may read are offered by name
- @e2e exclude pinned by `NewsAudienceOptionsTest::testGroupsFollowTheFieldReference`

#### Scenario: No source means a reference field
- GIVEN the school app declares no school source and the school field has no `$ref`
- WHEN a teacher chooses the whole school
- THEN the dialog asks for the school's reference
- @e2e exclude pinned by `NewsAudienceOptionsTest::testGroupsFollowTheFieldReference` (empty schools) and the dialog's fallback field
