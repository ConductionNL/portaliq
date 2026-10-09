---
status: proposed
---

# Spec: change-proposal-queue (a proposer lists and renders their own queue)

## ADDED Requirements

### Requirement: A proposer can list their own proposals (REQ-CPQ-005)

`ProposalService` SHALL expose `mine(string $proposedBy): array`, returning
every `changeProposal` (any state) whose `proposedBy` equals the given
reference. `ProposalController` SHALL expose
`GET /portal/api/proposals/mine`, bearer-gated through
`PortalSessionService::resolveFromBearer()`, and SHALL pass that resolved
subject's own `subjectRef` as `$proposedBy` — never a client-supplied value.
An unauthenticated request SHALL receive 401 and no query SHALL be issued.

#### Scenario: A guardian lists their own queued and decided proposals
- **GIVEN** a portal session belonging to subject `subjectRef = guardian-1`
- AND two proposals exist, one `proposedBy: guardian-1` and one `proposedBy: guardian-2`
- **WHEN** the guardian calls `GET /portal/api/proposals/mine`
- **THEN** only the `guardian-1` proposal is returned, in any state
- @e2e exclude backend filtering contract — covered by PHPUnit on `ProposalService::mine()` and `ProposalController::mine()`

#### Scenario: An unauthenticated request is refused
- **GIVEN** no valid bearer token
- **WHEN** `GET /portal/api/proposals/mine` is called
- **THEN** the response is 401 and no OpenRegister read is issued
- @e2e exclude fail-closed auth guard — covered by PHPUnit on `ProposalController::mine()`

### Requirement: The portal SPA can submit and withdraw a proposal (REQ-CPQ-006)

The portal SPA SHALL render a `type: propose-change` action as a form
offering exactly the action's `proposable` fields, pre-filled from the
current row, plus a note. Submitting SHALL send only the fields whose value
changed from the pre-filled one, as `{property, proposedValue}` pairs, to
`POST /portal/api/proposals`. A queued proposal the same subject made SHALL
be withdrawable from the same surface via
`POST /portal/api/proposals/{id}/withdraw`.

#### Scenario: A guardian proposes a change to one field
- **GIVEN** a `propose-change` action whose `proposable` list includes `phone`, on a detail row currently showing `phone: 0600000000`
- **WHEN** the guardian changes `phone` to `0611111111` and submits with a note
- **THEN** the request body carries exactly `changes: [{property: "phone", proposedValue: "0611111111"}]` and the note, and no other field
- @e2e exclude frontend submission shape — covered by a component test asserting the built request body

#### Scenario: A guardian withdraws a queued proposal
- **GIVEN** a queued proposal the guardian made, shown in their own proposals list
- **WHEN** the guardian withdraws it
- **THEN** `POST /portal/api/proposals/{id}/withdraw` is called and the list no longer offers a withdraw action on it
- @e2e exclude frontend/backend already covered — `ProposalService::withdraw()` PHPUnit, this scenario asserts the button's presence follows state
