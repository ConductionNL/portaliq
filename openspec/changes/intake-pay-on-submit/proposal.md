---
kind: code
depends_on: [portal-intake-form-as-an-object]
---

# Proposal: intake-pay-on-submit

> Retargeted 2026-10-01 (`site-reaches-portal-parity`): new frontend work in this change lands in the Vue site `src/site/`, not in the React portal `src/portal/`, which is being retired.

## Why

Many requests carry a fee: a parking permit, a copy of a certificate, an
event permit. Today a resident submits the form in the portal and then waits
for a payment request that the portal cannot take. The organisation chases
the payment by hand or sends a bank transfer request by mail.

Buildiq matrix, row `form-payment`, "Take an online payment as part of
submitting a form.", rated `no`, `built.owner` `ConductionNL/portaliq`,
origin `tender`, <https://www.tenderned.nl/aankondigingen/overzicht/382064>.
The row's note, verbatim: "Mined 2026-09-26 from tender: Katwijk
e-formulieren 2025." Its `built.evidence`, verbatim:

> Searched: grep -iE 'payment|betal|mollie|stripe|ideal' over buildiq src/ lib/ (only an icon label at src/utils/openGemeentenIcons.json:574) and portaliq origin/development lib/ (no hits); no form or portal step takes a payment

No competitor in the buildiq matrix is rated `yes`. `power-apps` is
`partial`, verbatim:

> docs-only: https://learn.microsoft.com/en-us/power-pages/admin/set-up-payments-integration (2026-09-26): Power Pages sets up payments integration (Stripe) on form steps (read via MicrosoftDocs/power-pages-docs power-pages-docs/admin/set-up-payments-integration.md); not available in canvas or model-driven apps Reached on: Power Pages, payments integration

Portaliq matrix, row `cmp-int-pay`, "Pay the fee when you submit a
request.", rated `no`, `built.state` `none`. One competitor is rated `yes`,
`xxllnc-pip`, verbatim:

> backend/perl-api/lib/Zaaksysteem/Controller/Zaak.pm:848 online payment per case type or rule; backend/perl-api/lib/Zaaksysteem/Controller/Plugins/Ogone.pm:80; backend/zaken/src/zsnl_domains/payments/interfaces/worldline.py [reached on webform finish; Ogone/Worldline]

`nl-portal` is `partial`, verbatim:

> frontend/packages/user-interface/src/components/Task.tsx:61 payment runs as a separate OGONEBETALING task [reached on /taken payment task; paying happens after the case system issues a payment task, not at submission in the portal; was yes from docs]

The portaliq row's `built.evidence`, verbatim:

> grep for pay/payment/mollie/ideal across lib/Controller/PortalIntakeController.php, lib/Service/Intake/, src/portal/components/SchemaForm.jsx: 0 hits; the only payment code anywhere in portaliq is the shillinq sibling contribution's invoice PortalPaymentSessionService, which is a customer-portal invoice-payment feature, unrelated to paying a fee on a new request

The lane recorded both rows as `build`: `form-payment` on the tender rule,
and `cmp-int-pay` as its mirror in this repo.

## What changes

- **The fee is the case type's.** The case app declares on the case type
  what the request costs and which of its actions takes the payment
  (`portalCaseType.portalFee`). The portal holds no price and takes no
  amount from the browser.
- **Pay straight after submitting.** When a resident submits a form whose
  case type declares a fee, the confirmation shows the amount and a "Pay
  {amount} now" button. It forwards the case app's declared pay action with
  the submission reference and the declared amount, and sends the resident
  to the checkout the case app returns.
- **The checkout must be a payment page the portal knows.** Portaliq sends
  the browser only to a host the portal declares in `paymentHosts`, so a
  compromised or misconfigured action cannot turn the portal into an open
  redirect.
- **Back from paying, the resident sees the result.** The return page reads
  the payment's state from integriq's `payment_intent` object in
  OpenRegister and says "Paid", "Not paid yet" with "Pay now" again, or
  "The payment failed".
- **A fee-bearing form needs a portal session.** Paying creates a financial
  obligation tied to a person, and the forward that asks the case app for a
  checkout runs on a session.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| buildiq | `form-payment` | Take an online payment as part of submitting a form. | no | A payment step at the end of submitting a published form. |
| portaliq | `cmp-int-pay` | Pay the fee when you submit a request. | no | The same, as the resident sees it: amount, checkout, result. |

## Existing work it builds on

- `portal-intake-form-as-an-object` (open): the binding, the submit route
  and the reference. The fee is shown on the confirmation it returns.
- `portal-contribution-contract` (spec): the A6 endpoint forward the pay
  action runs through.
- integriq, archived `2026-07-13-live-payment-providers`: the payment
  connector with a Mollie binding, iDEAL first, and the `payment_intent`
  object in register `openconnector` that records the status.

## Out of scope

- Invoice payment in the customer portal. That is shillinq's
  `PortalPaymentSessionService`, a different flow over a different object.
- Refunds, partial payments and payment in instalments.
- Anonymous payment. A fee-bearing form asks the visitor to sign in first.
- Choosing a payment provider. That is integriq's configuration.

## Sibling halves

- **The case app (ConductionNL/dossiq for municipal case types)** declares
  `portalFee` on its case types, offers the pay action in its contribution
  for the `client` audience, creates the payment through integriq's
  connector with the portal's return URL, returns
  `{checkoutUrl, paymentIntentId}`, and decides what an unpaid request
  becomes after its term. Not written here.
- **ConductionNL/integriq** already ships the connector
  (`2026-07-13-live-payment-providers`, all tasks checked). Nothing is owed
  unless task T01 finds `payment_intent` not readable by id through
  OpenRegister.
- **ConductionNL/buildiq** carries the `form-payment` row in its matrix; its
  forms declare no fee, and the fee stays on the case type.
