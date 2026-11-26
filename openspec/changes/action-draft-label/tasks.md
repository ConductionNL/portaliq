# Tasks: action-draft-label

- [x] **T1**: the normaliser keeps `draftLabel` only as a string; an attached action carries it
  - Files: lib/Contribution/ActionConfigNormaliser.php, lib/Contribution/AttachedActionResolver.php
  - Test: PHPUnit `ActionConfigNormaliserTest::testADraftLabelIsKeptOnlyAsText`, `AttachedActionResolverTest::testTheListingCarriesTheStepFlow`
- [x] **T2**: the site form's save button reads the action's `draftLabel`, else portaliq's words
  - Files: src/site/components/c/SchemaForm.vue
  - Test: `node --test tests/site-form-drafts.spec.mjs` ("the save button reads the action's own draftLabel")
- [ ] **T3**: Live: learniq's trainer form on the esdoornveen portal shows "Bewaren voor later" (learniq live lane, mbo-trainer-flows)
