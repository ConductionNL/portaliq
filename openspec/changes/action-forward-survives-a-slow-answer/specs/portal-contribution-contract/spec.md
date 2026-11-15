## ADDED Requirements

### Requirement: A repeated submission carries the same key (REQ-AFS-001)

A stepped form that sends through an endpoint action or an endpoint row action SHALL make one submission key when it opens and SHALL send it, as the `Idempotency-Key` header, with every send of the same answers, until a send succeeds; after a successful send it SHALL make a new key. Portaliq SHALL relay the key to the domain endpoint only when it is 16 to 128 characters of `A-Z`, `a-z`, `0-9`, `_` and `-`, and SHALL leave any other value out of the forward. Portaliq SHALL NOT read or store the key itself: answering a repeated key with the first send's result is the domain app's.

#### Scenario: A resident sends again after a slow answer
<!-- @e2e exclude The repeat needs a domain app slower than the forward timeout; pinned by tests/schema-form.spec.mjs ("a resend carries the same key"), tests/submission-key.spec.mjs and PortalActionForwarderTest::testTheSubmissionKeyIsRelayedAndAMalformedOneIsDropped. Live check: task 3.1. -->

- **GIVEN** a resident who opened the Woo request form on a dossier
- **WHEN** they send it, the answer is late, and they send it again
- **THEN** both sends carry the same `Idempotency-Key`, and dossiq answers the second with the case the first made

#### Scenario: A malformed key is not forwarded
<!-- @e2e exclude A header a browser form never makes; pinned by PortalActionForwarderTest::testTheSubmissionKeyIsRelayedAndAMalformedOneIsDropped. -->

- **GIVEN** a request whose `Idempotency-Key` is `not a key; drop table`
- **WHEN** portaliq forwards the action
- **THEN** the forward carries no `Idempotency-Key`

### Requirement: A forward that times out says the request may still be processing (REQ-AFS-002)

The server-to-server action forward SHALL wait up to 30 seconds. When the domain app does not answer in time, the action, row-action and guest-action forwards SHALL answer 504 `{error: forward_timeout}`; any other transport failure SHALL stay 502 `{error: forward_failed}`. A form that gets 504, or `{error: submission_processing}` from the domain app, SHALL NOT say the save failed: it SHALL say the request may still be processing, and link to Mijn zaken in the page's portal and addressing.

#### Scenario: The answer is late
<!-- @e2e exclude Needs a domain app slower than 30 s; pinned by ContributionControllerTest::testActionThatTimesOutIs504ForwardTimeout, PortalActionForwarderTest::testATimeoutIsToldApartFromAFailure and tests/schema-form.spec.mjs. -->

- **GIVEN** a domain app that answers after the forward timed out
- **WHEN** the resident sends the form
- **THEN** the form reads "Uw verzoek wordt misschien nog verwerkt. Kijk over een paar minuten in Mijn zaken voordat u het opnieuw verstuurt." with the link "Naar Mijn zaken"
- **AND** not "Opslaan is niet gelukt"

#### Scenario: A refused connection is still a failure
<!-- @e2e exclude Transport fault; pinned by ContributionControllerTest::testActionTransportFailureIs502ForwardFailed. -->

- **GIVEN** a domain endpoint that refuses the connection
- **WHEN** the action is forwarded
- **THEN** the answer is 502 `forward_failed`
