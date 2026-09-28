---
status: proposed
---

# Spec: portal-row-action-inputs

## Purpose

A button on a resident's own row can ask for input, can be offered only on
the rows where it applies, and shows the resident what happened. Requested by
filinq `signing-field-validation` and shillinq `sales-cancellation`.

## ADDED Requirements

### Requirement: A row action collects the fields it declares (REQ-RAI-001)

An endpoint row action that declares `fields` SHALL open a form of those
fields, shaped by its `fieldConfigs` and static `optionsProviders`, before it
forwards, and SHALL forward only the whitelisted fields. A field not marked
required MAY be left empty.

#### Scenario: A member cancels and says why
- **GIVEN** a customer signed in to the portal with an active subscription in shillinq's `subscriptions` collection, whose `cancel-subscription` row action declares an optional `reasonCode` with a static list of reasons
- **WHEN** the customer presses Cancel subscription on that row, picks Switched provider and confirms
- **THEN** shillinq receives `reasonCode: switched-provider` with the subscription's id stamped by portaliq

#### Scenario: A member cancels without a reason
- **GIVEN** the same customer
- **WHEN** they leave the reason empty and confirm
- **THEN** the forward carries no `reasonCode` and is not refused by portaliq

### Requirement: A row action collects the inputs its row declares (REQ-RAI-002)

An endpoint row action that declares `rowInputs` SHALL show one input per
descriptor in the named row field of the selected row, and the server SHALL
forward values only for the names that row declares, under the declared body
key, refusing with 422 and forwarding nothing when a required input is
empty.

#### Scenario: A signer fills in their IBAN
- **GIVEN** a resident with a signing request in filinq's signer collection whose row declares one required input `iban` labelled "Bank account"
- **WHEN** the resident opens Sign on that row, types an IBAN and confirms
- **THEN** filinq receives `fields: {iban: "<typed value>"}` beside the stamped signing request id

#### Scenario: An input the row does not declare is dropped
- **GIVEN** the same row
- **WHEN** a hand-crafted post adds `fields: {iban: "...", email: "x@example.org"}`
- **THEN** filinq receives only `iban`
- @e2e exclude tamper assertion on a hand-crafted body; pinned by ContributionControllerTest

### Requirement: The resident sees what happened (REQ-RAI-003)

After a forward the dialog SHALL show the target's `message` on success, the
target's messages under the named inputs on a 422 that names them, and the
target's message or a general sentence on any other refusal, and SHALL keep
the dialog open on every refusal.

#### Scenario: A wrong IBAN is refused on its field
- **GIVEN** the resident signing with a required IBAN
- **WHEN** filinq answers 422 with `errors: {iban: "This is not a valid IBAN."}`
- **THEN** that sentence shows under Bank account and the dialog stays open

#### Scenario: The cancellation date is shown
- **GIVEN** the customer cancelling a subscription
- **WHEN** shillinq answers 200 with `message: "Your subscription ends on 31 October 2026."`
- **THEN** the portal shows that sentence and reloads the subscriptions

### Requirement: A row action can be offered on some rows only (REQ-RAI-004)

An endpoint row action that declares `availableWhen` SHALL be shown only on
rows where the named field equals the declared value; on other rows the
portal SHALL show the text of `unavailableReasonField` instead, and the row
forward SHALL answer 409 with that reason and forward nothing.

#### Scenario: The withdrawal period has ended
- **GIVEN** a consumer signed in to the portal with a booking whose row says `withdrawable: false` and `withdrawalBlockedReason: "The withdrawal period ended on 4 October 2026."`
- **WHEN** they open their bookings
- **THEN** that booking shows no withdraw button and shows the sentence about the period instead

#### Scenario: A hand-crafted withdrawal on that row is refused
- **GIVEN** the same booking
- **WHEN** a post reaches the row forward for `withdraw` on it
- **THEN** the answer is 409 with the reason and nothing reaches shillinq
- @e2e exclude refusal at the server seam; pinned by ContributionControllerTest

### Requirement: The button and the confirmation use the contribution's words (REQ-RAI-005)

A row action button SHALL show the action's `label` as the contribution
declares it, and the confirmation step SHALL show its `confirmText` when
declared, without portaliq rewording either.

#### Scenario: The withdrawal button reads as the law asks
- **GIVEN** shillinq's `withdraw` action labelled "Withdraw from contract here" with a confirmation text
- **WHEN** a consumer with a withdrawable booking presses it
- **THEN** the button read "Withdraw from contract here" and a confirmation step with shillinq's text comes before anything is forwarded
