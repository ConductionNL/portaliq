---
status: proposed
---

# Spec: portal-intake-payment

## Purpose

A resident pays the fee for a request as part of submitting it, and sees
whether the payment went through. The case app sets the fee and takes the
payment through integriq; portaliq shows it and forwards. Buildiq matrix row
`form-payment` (TenderNed 382064) and portaliq matrix row `cmp-int-pay`.

## ADDED Requirements

### Requirement: The fee comes from the case type (REQ-IPS-001)

Portaliq SHALL read a request's fee only from `portalCaseType.portalFee` of
the binding's case type. The amount forwarded to the case app SHALL be the
declared amount, and any amount in the request body SHALL be ignored.

#### Scenario: A resident sees the declared fee
- **GIVEN** a parking permit form whose case type declares a fee of 45.00 EUR
- **WHEN** a signed-in resident submits it
- **THEN** the confirmation says "This request costs €45.00." and offers "Pay €45.00 now"
- e2e: `tests/e2e/intake-pay-on-submit.spec.ts`

#### Scenario: The browser cannot lower the fee
- **GIVEN** the same form
- **WHEN** a crafted pay request carries `amount: "0.01"`
- **THEN** the case app receives `amount: "45.00"`
- @e2e exclude Tamper assertion on a crafted body; pinned by PortalIntakeControllerTest::testPayForwardsTheDeclaredAmount

### Requirement: A fee-bearing form asks the visitor to sign in first (REQ-IPS-002)

A binding whose case type declares a fee SHALL require a portal session at
`substantial` trust. An anonymous visitor SHALL see "Log in to submit this
request. It has a fee of {amount}." before the first question.

#### Scenario: An anonymous visitor is asked to sign in
- **GIVEN** a visitor without a session on a fee-bearing form
- **WHEN** the form loads
- **THEN** they see the sign-in message with the amount and no questions
- e2e: `tests/e2e/intake-pay-on-submit.spec.ts`

### Requirement: Only the submitter can pay, once (REQ-IPS-003)

`POST /portal/api/intake/pay` SHALL return 401 without a session, 404 when
the reference is not the subject's own, 409 when the case type declares no
fee or the submission is already paid, and 403 when the declared pay action
is not in the subject's own manifest. It SHALL forward nothing on any of
these.

#### Scenario: A resident cannot pay for someone else's request
- **GIVEN** two residents and a reference that belongs to the first
- **WHEN** the second calls the pay route with it
- **THEN** the response is 404 and nothing is forwarded
- @e2e exclude Server-side refusal; pinned by PortalIntakeControllerTest

### Requirement: The portal redirects only to a declared payment host (REQ-IPS-004)

The pay route SHALL return a `checkoutUrl` only when it uses `https` and its
host is in the portal's `paymentHosts`. Any other URL SHALL give 502
`payment_unavailable`, and the resident SHALL see "You cannot pay right now.
Try again later."

#### Scenario: A checkout on an unknown host is refused
- **GIVEN** a portal whose `paymentHosts` is `["www.mollie.com"]` and a case app that returns `https://pay.example.org/checkout`
- **WHEN** the resident presses "Pay now"
- **THEN** they stay on the portal and see "You cannot pay right now. Try again later."
- @e2e exclude Redirect guard; pinned by PortalIntakeControllerTest::testUndeclaredCheckoutHostIsRefused

### Requirement: The result is read from the payment record (REQ-IPS-005)

The status route SHALL report the payment state read from integriq's
`payment_intent` object for the submission's `paymentIntentId`, and the
return page SHALL show "Paid", "Not paid yet", "The payment failed" or "We
cannot show the payment yet". A query-string status SHALL NOT change what is
shown.

#### Scenario: A resident comes back after paying
- **GIVEN** a resident whose payment intent reads `paid`
- **WHEN** they land on the return page
- **THEN** the page shows their reference and "Paid"
- e2e: `tests/e2e/intake-pay-on-submit.spec.ts`

#### Scenario: A resident who cancelled can pay again
- **GIVEN** a resident whose payment intent reads `canceled`
- **WHEN** they land on the return page
- **THEN** the page shows "The payment failed" and "Pay now"
- e2e: `tests/e2e/intake-pay-on-submit.spec.ts`
