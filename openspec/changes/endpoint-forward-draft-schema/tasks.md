# Tasks: endpoint-forward-draft-schema

- [x] **T1**: the normaliser keeps `draftSchema` only on an endpoint action with a `draft`, as `{register, schema}` slugs
  - Files: lib/Contribution/EndpointDraftSchema.php, lib/Contribution/FormStepsNormaliser.php
  - Test: PHPUnit `EndpointDraftSchemaTest`
- [x] **T2**: `ActionDraft` keeps, finds and lists an endpoint action's draft on its `draftSchema`, stamped with the action's subject field
  - Files: lib/Service/Intake/ActionDraft.php
  - Test: PHPUnit `ActionDraftTest::testAnEndpointActionHoldsItsDraftOnItsDraftSchema`, `EndpointDraftSchemaTest::testTheDraftIsKeptOnTheDeclaredSchemaStampedWithTheSubjectField`
- [x] **T3**: an accepted forward discards the draft; a refused one keeps it; a failing discard never changes the answer
  - Files: lib/Controller/ContributionController.php
  - Test: PHPUnit `ContributionControllerForwardDraftTest`
- [x] **T4**: the site form resumes and offers "save for later" for an endpoint action with `draftSchema`
  - Files: src/site/components/c/SchemaForm.vue
  - Test: `node --test tests/site-form-drafts.spec.mjs` ("an endpoint action that declares draftSchema keeps its draft there")
- [ ] **T5**: Live: a trainer on the esdoornveen portal saves a werkproces assessment halfway and finishes it later (learniq live lane, mbo-trainer-flows)
- [x] **T6**: an action block hands its form the action with the contributing app as `appId`, so an endpoint action's draft is kept and found under that app (found live 26 Nov: it was asked as `/drafts/portal/createWerkprocesAssessment` and refused 404)
  - Files: src/site/components/c/ActionBlock.vue
  - Test: `node --test tests/site-form-drafts.spec.mjs` ("an action block keys an endpoint action's draft by the app that contributed it")
