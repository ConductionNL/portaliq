---
status: proposed
---

# Spec: portal-contribution-contract (timed tasks)

## ADDED Requirements

### Requirement: A collection MUST be able to declare a timed task driven by five endpoint actions

A collection with `kind: timedTask` SHALL carry a `timedTask` block naming the
ids of five actions in the same contribution: `available`, `start`, `answer`,
`submit` and `result`. Each named action SHALL be an endpoint action (an
instance-local `endpoint`). When any of the five is missing, names an action
that does not exist in the contribution after trust filtering, or names an
action without an endpoint, the normaliser SHALL remove the `timedTask` block
and the `kind`, so the collection renders as an ordinary list.

#### Scenario: A sound timed task survives normalisation
@e2e exclude {a manifest shape; asserted in tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php::testASoundTimedTaskIsKept}

- **GIVEN** a collection `studentTests` with `kind: timedTask` and a block naming five endpoint actions of the same contribution
- **WHEN** the manifest is normalised
- **THEN** the collection SHALL keep `kind: timedTask` and the five action ids

#### Scenario: A block naming a missing or non-endpoint action is dropped
@e2e exclude {a manifest shape; asserted in tests/Unit/Contribution/TimedTaskConfigNormaliserTest.php::testABrokenTimedTaskFallsBackToAList}

- **GIVEN** a `timedTask` block whose `submit` names a trust-dropped action, and another whose `answer` names a `type: create` action without an endpoint
- **WHEN** the manifest is normalised
- **THEN** neither collection SHALL carry `kind` or `timedTask`

### Requirement: An endpoint action MUST be able to receive the subject's scope from the server

An endpoint action SHALL be able to declare `subjectField` (a field name
matching `^[a-zA-Z][a-zA-Z0-9_]*$`). When it does, the forward SHALL resolve the
action's scope value the way reads do (its `scopeClaim` from the subject's own
portal account, else the subject reference) and SHALL set that value in the
forwarded JSON body under `subjectField`, over any value the client sent, with
the body built from the action's `fields` whitelist (none declared means an
empty whitelist). When the value does not resolve, the forward SHALL answer 403
and SHALL NOT call the endpoint. A malformed `subjectField` SHALL remove the
action from the manifest.

#### Scenario: The learner reference comes from the server, not the browser
@e2e exclude {the attack is a hand-crafted body; asserted in tests/Unit/Controller/ContributionControllerSubjectFieldTest.php::testTheResolvedScopeOverridesAClientValue}

- **GIVEN** an endpoint action with `subjectField: learnerRef` and `scopeClaim: learnerRef`
- **WHEN** a pupil forwards it with `learnerRef: "someone-else"` in the body
- **THEN** the domain app SHALL receive the learnerRef resolved from the pupil's own account

#### Scenario: An unresolvable scope never reaches the domain app
@e2e exclude {an absence of an outbound call; asserted in tests/Unit/Controller/ContributionControllerSubjectFieldTest.php::testAnUnresolvableScopeIs403WithoutForwarding}

- **GIVEN** that action and a subject whose account has no `learnerRef` claim
- **WHEN** the subject forwards it
- **THEN** the answer SHALL be 403 and no request SHALL be made

### Requirement: The portal MUST let a subject take a timed task

For a `timedTask` collection the portal SHALL list the tasks `available`
returns and the subject's attempts, SHALL start an attempt through `start`
(asking for an access code when a task says it needs one), SHALL show one
question at a time with its renderer (choice, inline choice, text entry,
extended text, order, match, and a text answer for any other type), SHALL save
each answer through `answer` when it changes and when the subject navigates,
SHALL show which answers are not yet saved, SHALL count down to the attempt's
`deadlineAt` corrected by `serverNow`, SHALL submit through `submit` when the
subject confirms or the countdown reaches zero, and SHALL show the `result`
response read-only, or that the result is not released yet.

#### Scenario: A timed attempt from start to submit
@e2e exclude {learniq does not ship the endpoints yet, so no live contribution declares a timed task; the flow is driven against a fake api in tests/timed-task.spec.mjs::runs an attempt from start to submit}

- **GIVEN** a task with a 20 minute deadline and three questions
- **WHEN** the pupil starts it, answers two questions and submits
- **THEN** each answer SHALL be sent once per change with the attempt id and item id
- **AND** submit SHALL flush any unsaved answer before it is sent

#### Scenario: The countdown follows the server's deadline
@e2e exclude {a clock computation; asserted in tests/timed-task.spec.mjs::counts down from the server deadline, not the client clock}

- **GIVEN** a server that says it is 09:00:00 with a deadline of 09:37:30, and a client clock five minutes ahead
- **WHEN** the countdown is computed
- **THEN** it SHALL read 37:30

#### Scenario: Every item type renders its own control
@e2e exclude {rendered with react-dom/server in tests/timed-task.spec.mjs::renders a control per item type}

- **GIVEN** one item of each supported type and one hotspot item
- **WHEN** the question screen renders each
- **THEN** choice SHALL be radio buttons, inline choice a select, text entry an input, extended text a textarea, order a list with move buttons, match a select per source, and hotspot a text answer
