## ADDED Requirements

### Requirement: A refusal by the record's own app MUST read as no longer possible (REQ-ORR-001)

When the app that owns a record refuses a portal create before it is stored (OpenRegister's creating hook stops the write), the portal MUST answer 409 `{error: "refused"}`, never 502, and MUST NOT repeat the app's text. A value the store refused MUST still answer 422 `invalid`, and a store that fails for any other reason MUST still answer 502 `write_failed`.

#### Scenario: A resident reacts on a closed consultation
@e2e exclude decidiq tests/e2e/portal-citizen-contribution.spec.ts (the owning app's spec runs it end to end)
- GIVEN decidiq refuses a reaction on a consultation whose status is closed
- WHEN a resident submits that reaction through the portal
- THEN the portal answers 409 `{error: "refused"}`
- AND the form says "Dit kan voor dit item niet meer."

#### Scenario: A store failure stays a failure
@e2e exclude PHPUnit ContributionControllerOwnerRefusalTest
- GIVEN OpenRegister fails on the write for another reason than a refusal
- WHEN a resident submits a form
- THEN the portal answers 502 `write_failed`
