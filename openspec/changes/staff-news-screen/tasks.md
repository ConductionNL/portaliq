# Tasks: staff-news-screen

- [x] **T1**: `PUT /api/news/{id}` changes title, text and audience only, behind the same staff guard
  - PHPUnit `NewsControllerTest::testUpdateChangesTheTextAndAudienceOnly`, `testUpdateAndAudiencesRefuseAnUnauthenticatedCaller`
- [x] **T2**: `GET /api/news/audiences` and `NewsAudienceOptions`: school and group choices from the school app's `guardianAudience`
  - PHPUnit `NewsAudienceOptionsTest`, `NewsControllerTest::testAudiencesListsTheSchoolAndGroupChoices`
- [x] **T3**: `src/lib/newsAuthoring.js`: form, target, missing fields, audience line, calls over the authoring routes
  - `node --test tests/news-authoring.spec.mjs`
- [x] **T4**: the News page (`src/views/NewsAuthoring.vue`, `src/dialogs/NewsItemDialog.vue`), manifest page and menu entry, registry entry, Dutch translations
  - `node --test tests/news-authoring.spec.mjs` (wiring); live: a teacher writes, changes and publishes a school-wide item at the primary school and the guardian reads it
