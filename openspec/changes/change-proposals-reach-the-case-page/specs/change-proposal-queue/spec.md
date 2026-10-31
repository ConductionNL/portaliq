## ADDED Requirements

### Requirement: A colleague who may read a record proposes from the queue widget (REQ-CPC-001)

`portaliq-change-proposal-queue` SHALL show a user who may review proposals on the record the queued proposals with accept and reject, as it does today. It SHALL show a user who may read the record but not review proposals a form "Wijziging voorstellen" with exactly the record's proposable properties, each prefilled with its current value, and a note. Submitting SHALL send only the properties whose value changed to `POST /apps/portaliq/api/proposals`. Below the form the widget SHALL list that user's own proposals on this record in any state, each queued one with a withdraw button. A user who may not read the record SHALL see nothing.

#### Scenario: A colleague proposes a new phone number
- **GIVEN** a colleague with read but no review right on case 2026-0082, whose proposable properties are `applicantPhone` and `applicantEmail`
- **WHEN** they open the case, change `applicantPhone` in "Wijziging voorstellen" and submit with a note
- **THEN** a proposal is queued with only `applicantPhone`, channel `staff`, and it shows under their own proposals with a withdraw button
- e2e: `tests/e2e/change-proposals-reach-the-case-page.spec.ts`

#### Scenario: A reviewer sees the queue, not the form
- **GIVEN** a handler with the review right on the same case
- **WHEN** they open the case
- **THEN** the widget lists the queued proposal with accept and reject
- e2e: `tests/e2e/change-proposals-reach-the-case-page.spec.ts`

### Requirement: A colleague lists their own proposals on a record (REQ-CPC-002)

`GET /apps/portaliq/api/proposals/mine` with `register`, `schema` and `id` SHALL return the proposals on that record whose `proposedBy` is the signed-in user, in any state, and only when that user may read the record. A user who may not read it SHALL get 403 and no proposal.

#### Scenario: Only their own
- **GIVEN** two colleagues who each proposed a change on the same case
- **WHEN** one of them asks for their own proposals on it
- **THEN** only their proposal is returned
- @e2e exclude Backend filter; pinned by ProposalControllerTest

### Requirement: The colleague route takes the allow-list from the server (REQ-CPC-003)

`POST /apps/portaliq/api/proposals` SHALL ignore any `proposable` list in the request. It SHALL allow only the properties the owning app's contribution declares as `proposable` on a `propose-change` action for a collection on that register and schema, joined across audiences. A record whose register and schema no contribution declares a `propose-change` for SHALL take no colleague proposal, with 403 `not_proposable`.

#### Scenario: A property outside the declaration
- **GIVEN** dossiq declares `applicantPhone` as proposable on its cases
- **WHEN** a colleague posts a proposal for `status` with `proposable: ["status"]` in the request
- **THEN** the answer is 422 `property_not_proposable` and no proposal exists
- @e2e exclude Server-side allow-list; pinned by ProposalControllerTest
