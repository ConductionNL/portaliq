# Tasks: intake-pay-on-submit

## Before any code

- [ ] **T01**: On a live instance with integriq's `log` payment provider, create a payment and read the `payment_intent` object by id through OpenRegister with RBAC off, as `PortalObjectReader` does. Record the register and schema slugs. Verification: the call and result in the PR body. — not run: needs a live instance; `PortalPaymentIntents` assumes register `openconnector`, schema `payment_intent`, field `paymentStatus` as the design states

## Declarations

- [x] **T02**: `portalCaseType.portalFee` (`amount`, `currency`, `description`, `payAction`), `portalIntakeSubmission.paymentIntentId`, `portal.paymentHosts` in `lib/Settings/portaliq_register.json`, with a register version bump (REQ-IPS-001, REQ-IPS-004). Verification: the register import test.
- [x] **T03**: The render payload carries the declared fee; a fee-bearing binding requires `substantial` (REQ-IPS-001, REQ-IPS-002). Verification: `PortalFormBindingResolverTest::testFeeRequiresSession`.

## The pay route

- [x] **T04**: `PortalIntakeController::pay()` and route `POST /portal/api/intake/pay`: 401, 404, 409, 403, server-built body, forward, host check, store `paymentIntentId` (REQ-IPS-001, REQ-IPS-003, REQ-IPS-004). Verification: `PortalIntakeControllerTest::testPayForwardsTheDeclaredAmount`, `::testForeignReferenceIs404`, `::testPaidSubmissionIs409`, `::testUndeclaredCheckoutHostIsRefused`.
- [x] **T05**: `status()` adds `payment.state` from the `payment_intent` object (REQ-IPS-005). Verification: `PortalIntakeControllerTest::testStatusReadsPaymentFromTheIntent`, `::testQueryStringDoesNotSetPaymentState`.

## The screen

- [x] **T06**: The fee line, "Pay {amount} now", top-window navigation from the embed (its Vue `EmbeddedForm.vue`, not the React one), the return page states and the sign-in message on the confirmation surfaces (REQ-IPS-002, REQ-IPS-005). Verification: `tests/intake-pay.spec.mjs`. The embed frame is not changed (it renders its own confirmation) and the Playwright run with a stub case app is not run: needs a live instance.

## Docs, strings and validation

- [x] **T07**: English and Dutch strings ("This request costs {amount}.", "Pay {amount} now", "Paid", "Not paid yet", "The payment failed", "We cannot show the payment yet", "You cannot pay right now. Try again later.", "Log in to submit this request. It has a fee of {amount}."); a docs page for administrators on `paymentHosts` and for case app authors on `portalFee`. Verification: `npm run lint`, `test:l10n`.
- [ ] **T08**: `openspec validate intake-pay-on-submit --strict`. — not run: the openspec CLI is not installed here

## Build notes

- The fee, the allowed checkout host and the payment states live in `PortalFee`; the payment record is read by `PortalPaymentIntents`. Both are optional constructor arguments of `PortalIntakeController`, so a portal without them offers no payment.
- The administrator docs page is `docs/operations/intake-payment.md`.
- The admin settings page for `paymentHosts` is not built; the list is edited on the portal object.
