# portal-access-requests Specification

## Purpose
A portal user asks for access to cases they cannot see yet, the organisation
answers in the product, and a granted request actually opens the cases.
Requested by the portaliq parity matrix rows `id-request-access` and
`id-my-access-requests`.

## Requirements

### Requirement: You ask for access and follow your request (REQ-IAR-001)

A signed-in portal user SHALL be able to ask for access to a named party's
cases, giving a reason, and SHALL see every request they made with its state
and, for a refusal, the reason given. A request without a reason SHALL be
refused.

#### Scenario: A bookkeeper asks for a client's cases
- **GIVEN** a business user signed in to the portal with no mandate for company 87654321
- **WHEN** they choose "Ask for access to cases", enter 87654321 and a reason, and send it
- **THEN** their request list shows it as pending

#### Scenario: A refusal shows its reason
- **GIVEN** a request the organisation refused with the reason "No authorisation from the company"
- **WHEN** the asker opens their request list
- **THEN** they see the request as refused, with that reason

### Requirement: Staff answer the requests of their organisation (REQ-IAR-002)

A staff member holding the action `portal.answer-access-request` SHALL see the
pending requests of their organisation and SHALL be able to grant or refuse
each one; a refusal SHALL need a reason. A staff member without the action
SHALL be refused, and a request of another organisation SHALL answer as not
found.

#### Scenario: A clerk refuses without a reason
- **GIVEN** a clerk holding `portal.answer-access-request`
- **WHEN** they refuse a request and leave the reason empty
- **THEN** the refusal is not saved and the dialog reads "Give a reason for the refusal."

#### Scenario: A colleague without the action cannot answer

@e2e exclude needs a second Nextcloud user without the action; the 403 and the untouched request are covered by PHPUnit AccessRequestAdminControllerTest::testAUserWithoutTheActionGets403AndReachesNothing

- **GIVEN** a Nextcloud user without `portal.answer-access-request`
- **WHEN** they call `POST /apps/portaliq/api/access-requests/{id}/grant`
- **THEN** the answer is 403 and the request stays pending

### Requirement: A granted request opens the cases (REQ-IAR-003)

Granting a request SHALL record an active mandate for the asker on behalf of
the named party, so the party's cases appear on the asker's "My cases". A grant
SHALL NOT read as granted when the mandate could not be recorded.

#### Scenario: After a grant the cases appear

@e2e exclude the portal has no "My cases" page yet (cases-my-cases-page); the mandate the grant writes is covered by PHPUnit PortalAccessRequestServiceTest::testAGrantRecordsTheMandateThatOpensTheCases and testAGrantWhoseMandateFailsLeavesTheRequestPending

- **GIVEN** a pending request from a bookkeeper for company 87654321
- **WHEN** a clerk grants it
- **THEN** the bookkeeper's "My cases" lists the cases of 87654321, labelled "Granted on request"
