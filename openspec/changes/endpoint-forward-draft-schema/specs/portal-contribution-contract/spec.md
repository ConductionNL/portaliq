# portal-contribution-contract Delta: endpoint-forward-draft-schema

## ADDED Requirements

### Requirement: An endpoint action MAY keep its draft on a declared schema

An action that is not a `create`, names an `endpoint` and keeps a `draft` MAY declare `draftSchema` with a `register` and a `schema`. The normaliser MUST keep `draftSchema` only on such an action and only when both are slugs, and MUST drop it otherwise. portaliq MUST keep the resident's unfinished answers of that action on that schema through OpenRegister's drafts, under the same owner, retention and lookup as a create action's draft (REQ-SMF-021), stamped with the action's `subjectField`, else its `scopeField`, else `subjectRef`. The endpoint MUST receive only the final body. Once the endpoint answers 2xx, portaliq MUST discard the resident's draft of that action; a refused body MUST keep it; a discard that fails MUST NOT change the answer.

#### Scenario: The trainer saves an assessment halfway
- GIVEN learniq's `createWerkprocesAssessment` is endpoint-forward with `draft: {retentionDays: 30}` and `draftSchema: {register: learniq, schema: werkproces-assessment-draft}`
- WHEN the trainer fills in the first step and chooses "Opslaan als concept"
- THEN the answers are kept as a draft on `werkproces-assessment-draft`, stamped with her `practicalTrainerId`
- AND learniq's endpoint receives nothing
- @e2e exclude covered by PHPUnit `ActionDraftTest::testAnEndpointActionHoldsItsDraftOnItsDraftSchema` and `node --test tests/site-form-drafts.spec.mjs`; the live walk is learniq's mbo-trainer-flows spec

#### Scenario: The draft is kept under the app that contributed the action
- GIVEN the trainer's form opens from an action block on her overview, and the action names no `appId` and no `register`
- WHEN the form reads or keeps her draft
- THEN it asks under `learniq`, the app whose contribution declared the action, not under `portal`
- @e2e exclude covered by `node --test tests/site-form-drafts.spec.mjs`; the live walk is learniq's mbo-trainer-flows spec

#### Scenario: Sending the finished assessment ends the draft
- GIVEN the trainer resumed her draft and sends the assessment
- WHEN learniq's endpoint answers 201
- THEN her draft on `werkproces-assessment-draft` is discarded
- @e2e exclude covered by PHPUnit `ContributionControllerForwardDraftTest::testAnAcceptedBodyDiscardsTheDraft`

#### Scenario: A refused assessment keeps the draft
- GIVEN the trainer sends the assessment
- WHEN learniq's endpoint answers 422
- THEN her draft is kept
- @e2e exclude covered by PHPUnit `ContributionControllerForwardDraftTest::testARefusedBodyOrAnActionWithoutADraftSchemaKeepsIt`

#### Scenario: A draft schema on a create action is dropped
- GIVEN a create action that declares `draftSchema`
- WHEN the manifest is normalised
- THEN `draftSchema` is dropped and the draft stays on the action's own schema
- @e2e exclude covered by PHPUnit `EndpointDraftSchemaTest::testTheDraftSchemaIsDroppedWhereItCannotHold`
