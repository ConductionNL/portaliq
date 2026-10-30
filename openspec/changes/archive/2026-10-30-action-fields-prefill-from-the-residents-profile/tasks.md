# Tasks: action-fields-prefill-from-the-residents-profile

- [x] **T1**: `lib/Contribution/ProfilePrefillKey.php` keeps `prefill: name|email` on an action field config (through `ActionFormShape::fieldWidget()`)
  - PHPUnit `ActionConfigNormaliserTest::testAPrefillSourceIsKeptOnlyWhenKnown`
- [x] **T2**: `src/site/components/forms/profilePrefill.js` and `SchemaForm.vue` fill empty prefill fields from `api.getDetails()` after the draft and on a new action
  - node `tests/profile-prefill.spec.mjs` (4 tests), wired into `check:specs`
