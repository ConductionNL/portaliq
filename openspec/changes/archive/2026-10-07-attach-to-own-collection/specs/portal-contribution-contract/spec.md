---
status: proposed
---

# Spec: portal-contribution-contract

## Purpose

An app offers a form action on one of its own records, on that record's detail
only, and only while the record's state allows it. Journey J4.3 in hydra
`openspec/changes/woo-citizen-journey`: the resident replies to the answer.

## ADDED Requirements

### Requirement: An attached action MUST be able to name one collection and its own app (REQ-ATO-001)

An endpoint action whose `attachTo` carries a `collection` SHALL be listed in
`attachedActions` only on that collection of `attachTo.app` (and schema). A
`collection` that is not a plain name SHALL attach nothing. `attachTo.app` MAY
be the declaring app itself. A forward that names `actionApp` SHALL be
authorised as an attached action, whatever app it names, so an action attached
to its own app's collection forwards without being one of that collection's
`rowActions`.

#### Scenario: pipelinq's reply lands on the questions only
- **GIVEN** pipelinq offers `replyToQuestion` with `attachTo: { app: "pipelinq", schema: "ticket", collection: "myQuestions" }` and has collections `ownRequests` and `myQuestions` on `ticket`
- **WHEN** the aggregate is resolved
- **THEN** `myQuestions` lists `replyToQuestion` and `ownRequests` does not
- test: PHPUnit `tests/Unit/Contribution/AttachedActionResolverTest.php` ("attach to collection narrows to that collection")

#### Scenario: The reply is forwarded with the proven question
- **GIVEN** the resident's question is waiting for them
- **WHEN** they send "Dank u, nog een vraag." on it
- **THEN** pipelinq receives `{ ticket: <question id>, message: "Dank u, nog een vraag." }`, never a ticket id from the browser
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("an action attached to its own collection forwards")

#### Scenario: A forged listing on another collection
- **GIVEN** a request names `replyToQuestion` on `ownRequests`
- **WHEN** the forward looks the action up
- **THEN** nothing is found and the answer is 403
- test: PHPUnit `tests/Unit/Contribution/AttachedActionResolverTest.php` ("the forward lookup honours the collection")

### Requirement: An attached action MUST carry its rowWhen to the renderer (REQ-ATO-002)

The listing of an attached action SHALL carry its `rowWhen`. A renderer SHALL
leave the action off a record whose field does not hold one of the listed
values. The forward SHALL refuse such a record with 409 and forward nothing.

#### Scenario: The reply shows while the question waits for the resident
- **GIVEN** `replyToQuestion` has `rowWhen: { field: "status", in: ["awaiting_customer"] }`
- **WHEN** the resident opens a question with status `awaiting_customer`, and then one with status `converted`
- **THEN** the first shows "Reageren op het antwoord" and the second does not
- test: `tests/attached-actions.spec.mjs` ("an attached action shows only on the rows its rowWhen names")

#### Scenario: A reply on a converted question
- **GIVEN** a question with status `converted`
- **WHEN** a client forwards `replyToQuestion` on it anyway
- **THEN** the answer is 409 and nothing is forwarded
- test: PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("an attached action outside its rowWhen is 409")
