## MODIFIED Requirements

### Requirement: A granted request opens the cases (REQ-IAR-003)

Granting a request SHALL record an active mandate for the asker on behalf of the named party, so the party's cases appear on the asker's "My cases". The mandate SHALL be labelled with the party as the asker named it. A grant SHALL NOT read as granted when the mandate could not be recorded.

#### Scenario: After a grant the cases appear

@e2e exclude covered by PHPUnit PortalAccessRequestServiceTest::testAGrantRecordsTheMandateThatOpensTheCases and testAGrantWhoseMandateFailsLeavesTheRequestPending

- **GIVEN** a pending request from a bookkeeper for company 87654321
- **WHEN** a clerk grants it
- **THEN** the bookkeeper's "My cases" lists the cases of 87654321, labelled "87654321"
- **AND** acting for it reads "U regelt nu zaken voor 87654321"
