# Design: intake-pay-on-submit

Read at portaliq `development` `eeda3fa`, integriq archived
`2026-07-13-live-payment-providers` and shillinq `development`.

## What exists

- `lib/Controller/PortalIntakeController.php:169-232` `submit()`: validates,
  queues through `PortalIntakeQueue::accept()`, and returns `reference`,
  `state` and `confirmationText`. No fee, no payment.
- `lib/BackgroundJob/PortalIntakeDeliveryJob.php:120` creates the case with
  `PortalObjectWriter::createAnonymousObject()` into the case app's register.
- `portalIntakeSubmission` in `lib/Settings/portaliq_register.json`:
  `reference, portal, route, subjectRef, origin, answers, state,
  failureReason, caseId, submittedAt, registeredAt`; `state` is
  `queued | registered | failed`.
- `portalCaseType` carries the case app's portal declarations
  (`portalWritable`, `portalWithdrawal`, `portalReportDeclaration`, and
  others). There is no fee.
- `lib/Service/PortalActionForwarder.php:91` `forward()` performs the A6
  forward with a signed assertion and a server-built body.
- integriq's `payment_intent` in register `openconnector` records
  `paymentStatus` (`open|pending|authorized|paid|failed|canceled|expired|refunded|chargeback`)
  and `checkoutUrl`, per the archived change's design.
- shillinq's `PortalPaymentSessionService` pays an existing invoice through
  its own adapter port, whose only binding on `development` is
  `LogMolliePaymentAdapter`. It is not reused.

## D1. The fee is a declaration on the case type

`portalCaseType.portalFee`, an object the case app fills:
`{amount: "12.50", currency: "EUR", description, payAction}`. `payAction` is
the id of an endpoint action in the case app's own contribution. The portal
reads it through the binding's case type at render time and at pay time. The
browser never sends an amount; the forwarded amount is the declaration's.

Built (changed while building): the declaration also names `payApp`, the app
whose contribution carries `payAction`. Action ids are per contribution
(shillinq already declares a `pay`), so an id alone could match another
app's action. The register requires `amount`, `payApp` and `payAction`.

## D2. A fee-bearing form needs a session

`PortalFormBindingResolver::requiredTrust()` (line 289) already decides a
form's trust. A binding whose case type declares `portalFee` requires a
session at `substantial`. The anonymous visitor sees "Log in to submit this
request. It has a fee of {amount}." before the first question, not after
the last one.

The A6 forward needs a subject for its assertion, and a payment tied to
nobody cannot be refunded or receipted. Anonymous payment is out of scope.

## D3. The pay route

New route `POST /portal/api/intake/pay`, `PortalIntakeController::pay(string $reference)`:

1. Resolve the subject; 401 without one.
2. Read the `portalIntakeSubmission` by `reference`; 404 unless its
   `subjectRef` is the subject's.
3. Read the binding's case type and its `portalFee`; 409 when none.
4. 409 when the submission already carries a `paymentIntentId` whose
   `payment_intent.paymentStatus` is `paid`.
5. Find `payAction` in the subject's own aggregated manifest for the case
   app, as `ContributionController::authorisedEndpointAction()` does; 403
   when absent.
6. Forward with the body `{reference, amount, currency, description,
   returnUrl}` built entirely server-side.
7. Accept only a 2xx body with `checkoutUrl` and `paymentIntentId`. Store
   `paymentIntentId` on the submission. Return `checkoutUrl` only when its
   scheme is `https` and its host is in the portal's `paymentHosts`;
   otherwise 502 with `payment_unavailable`.

`portalIntakeSubmission` gains `paymentIntentId` (string). `portal` gains
`paymentHosts` (array of host names, empty by default, so nothing redirects
until an administrator names the provider's host).

## D4. The result is read, not told

`returnUrl` points at the page that showed the confirmation, with
`?reference=`. `PortalIntakeController::status()` (line 246) adds
`payment: {state}` when the submission carries a `paymentIntentId`, read from
the `payment_intent` object through `PortalObjectReader` by id. The mapping
the resident sees:

| `paymentStatus` | Shown |
|---|---|
| `paid`, `authorized` | "Paid" |
| `open`, `pending` | "Not paid yet" with "Pay now" |
| `failed`, `canceled`, `expired` | "The payment failed" with "Pay now" |
| anything else | "We cannot show the payment yet" |

The query string never decides the state. A return URL with
`?status=paid` shows whatever the object says.

## D5. The screen

The confirmation surface that shows the reference (the embed frame
`src/portal/components/EmbeddedForm.jsx`, and the `intakeForm` widget when
`intake-conditional-questions-and-drafts` lands) shows, when the render
payload carries a fee: "This request costs {amount}." and a "Pay {amount}
now" button that calls the pay route and navigates to the returned
`checkoutUrl`. The embed frame opens the checkout in the top window, because
a payment page inside a third-party iframe is refused by most providers.

## Risks

- **The case app never declares a fee.** The row stays closed in code and
  dark in use. The sibling half is named.
- **A provider changes checkout host.** Payment stops with a clear 502 until
  the administrator updates `paymentHosts`. That is the price of refusing
  open redirects.
- **The case is created before payment.** The queue creates the case as
  today; an unpaid request is the case app's to handle after its term. The
  resident sees "Not paid yet" on the reference page until then.

## What this deliberately does not do

- Portaliq creates no payment and stores no card or bank data.
- No change to shillinq's invoice payment.
- No fee in buildiq's form definition.
