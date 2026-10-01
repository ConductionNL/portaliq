# Proposal: staff-news-screen

## Why

Found testing a primary school end to end (2026-09-30), flow "school news to parents". News reached the parent, but a teacher could only write it through the staff authoring API (`POST /api/news`, `PUT /api/news/{id}/publish`). There was no screen in Nextcloud to write, change or publish a news item, so in practice only someone with a REST client could post school news.

## What changes

- A **News** page in portaliq's Nextcloud app (menu entry next to Notices), built like the Invitations and Access requests pages: a manifest index page on `newsItem` with no object-form Add, whose header action and row actions are handlers that call the staff routes. Staff see every news item with who it is for and whether it is published, write a new one (`New news item`), change one (`Change`), and publish it or take it back (`Publish`, `Take back`).
- Who a news item is for is one choice: **the whole school** or **one or more groups**. An item written for specific children (only the API can do that) keeps its children; the screen says so and lets staff change only the text.
- `PUT /api/news/{id}` changes a news item's title, text and audience. Status, author, photos, read receipts and translations stay as they are; publishing keeps its own endpoints.
- `GET /api/news/audiences` lists the schools and groups to choose from. They come from the school app's `guardianAudience` declaration (news-audience-from-the-school-app): an explicit `{register?, schema}` source (`groups.options`, `schoolOptions`), or the `$ref` the declared field carries in its own schema. The options are read as the signed-in staff member with OpenRegister's access rules on. When the school app offers no list, the screen asks for the reference instead.
- Who may author is unchanged: every route keeps `NewsController`'s guard (a signed-in Nextcloud user). The screen writes only through those routes, never through the object API, so the target check and the server-owned fields cannot be skipped.

## Not changed

- Newsletters and the emergency push have no screen yet.
- Photos on a news item (`photoRefs`) are not edited on this screen.
- learniq: its `enrolment.cohortId` carries `$ref: Cohort`, so the groups list works today. Its `learner-profile.schoolId` carries no `$ref`, so until learniq adds one (or declares `schoolOptions`), the school is entered as a reference. That is a one-line change in learniq, outside this change.
