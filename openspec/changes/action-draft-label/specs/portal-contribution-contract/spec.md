# portal-contribution-contract Delta: action-draft-label

## ADDED Requirements

### Requirement: An action MAY name its save-for-later button

An action that keeps a `draft` MAY declare `draftLabel`, a string. The normaliser MUST keep `draftLabel` only when it is a string and MUST drop it otherwise; an attached action MUST carry it to the record's form. The site form MUST show the action's `draftLabel` on its "save for later" button when it is not blank, and portaliq's own words otherwise.

#### Scenario: The trainer's form says "Bewaren voor later"
- GIVEN learniq's `createWerkprocesAssessment` declares `draft` and `draftLabel` "Keep for later", which learniq translates to "Bewaren voor later"
- WHEN the trainer opens the form on the esdoornveen portal
- THEN the button that keeps her answers reads "Bewaren voor later"
- @e2e exclude covered by `node --test tests/site-form-drafts.spec.mjs`; the live walk is learniq's mbo-trainer-flows spec

#### Scenario: Without a draftLabel the button keeps portaliq's words
- GIVEN an action with `draft` and no `draftLabel`
- WHEN the form opens
- THEN the button reads "Opslaan en later verdergaan"
- @e2e exclude covered by `node --test tests/site-form-drafts.spec.mjs`
