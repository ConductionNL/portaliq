# portal-contribution-contract Delta: a-create-stamps-the-moment-it-was-made

## ADDED Requirements

### Requirement: A create action may name the field that holds when it was made

A create action MAY declare `createdAtField`, a plain property name. When it does, the portal SHALL write the moment of the create into that field, as ISO 8601, over any value the client sent. A value that is not a plain property name SHALL stamp nothing.

#### Scenario: A question asked on the contact page is dated
@e2e exclude covered by CreateBodyTest
- **GIVEN** pipelinq's `askQuestion` declares `createdAtField: occurredAt`
- **WHEN** a resident sends a question at 07:41 UTC on 16 October 2026
- **THEN** the ticket's `occurredAt` is "2026-10-16T07:41:00+00:00"

#### Scenario: The client cannot choose the moment
@e2e exclude covered by CreateBodyTest
- **GIVEN** the same action
- **WHEN** the client sends its own `occurredAt`
- **THEN** the stored value is the moment of the create
