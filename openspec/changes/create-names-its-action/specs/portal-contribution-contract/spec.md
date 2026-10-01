## ADDED Requirements

### Requirement: A create writes through the action it names

A create MUST be matched to the subject's `create` action by the id the client sends as `actionId`, among the actions declared for the requested register and schema. An id that matches none of them MUST be refused with 403 and nothing written. When no id is sent, a single matching action MUST be used as before, and two or more MUST be refused with 400 `action_required` and nothing written. The portal frontend MUST send the id of the action whose form was filled in.

#### Scenario: A complaint is filed as a complaint

- GIVEN two create actions on one schema, a request form and a complaint form with different `defaults`
- WHEN a resident submits the complaint form
- THEN the object is written with the complaint form's defaults and whitelist
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testCreateWritesThroughTheActionItNames` and `tests/create-names-its-action.spec.mjs`

#### Scenario: An unknown action id is refused

- GIVEN a create naming an id the subject has no create action for on that register and schema
- WHEN it is submitted
- THEN the answer is 403 and nothing is written
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testCreateNamingAnUnknownActionIsRefused`

#### Scenario: Two actions and no id is refused, not guessed

- GIVEN two create actions on one schema
- WHEN a create arrives without an action id
- THEN the answer is 400 `action_required` and nothing is written
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testCreateWithoutAnIdBetweenTwoActionsIsRefusedNotGuessed`

#### Scenario: One action and no id keeps working

- GIVEN exactly one create action on a schema
- WHEN a create arrives without an action id
- THEN it is written through that action
- @e2e exclude covered by PHPUnit `ContributionControllerTest::testCreateWithoutAnIdAndOneActionStillWorks`
