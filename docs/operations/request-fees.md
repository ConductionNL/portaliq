---
title: Request fees
sidebar_label: Request fees
---

# Request fees

A request can cost money: a parking permit, a copy of a certificate. The resident pays the fee straight after sending the form, and the reference page tells them whether the payment went through.

Portaliq takes no payment itself and keeps no card or bank details. The case app sets the fee and takes the payment through integriq. The portal shows the fee, forwards it, and reads the result.

## For the case app: declare the fee

Put `portalFee` on the case type the form binding points at:

```json
{
  "portalFee": {
    "amount": "45.00",
    "currency": "EUR",
    "description": "Parkeervergunning bewoners",
    "payApp": "dossiq",
    "payAction": "pay-intake-fee"
  }
}
```

- `amount` is a decimal text with a point, more than zero. The portal forwards exactly this amount. An amount the browser sends is ignored.
- `payApp` and `payAction` name an endpoint action in that app's own portal contribution. It receives `reference`, `amount`, `currency`, `description` and `returnUrl`, and answers with `checkoutUrl` and `paymentIntentId`.
- A case type with a fee asks the resident to log in first, at level substantial. Without a session they read the fee and the request to log in before the first question.

## For the administrator: name the payment host

The portal sends a resident only to a checkout on `https` and on a host you named. Add the provider's host to the portal's `paymentHosts`, for example `www.mollie.com`.

The list is empty by default, so nothing redirects until you fill it in. A checkout on any other host shows the resident "U kunt nu niet betalen. Probeer het later opnieuw." and sends them nowhere.

## What the resident reads

After paying, the resident comes back with their reference. The state comes from integriq's payment record, never from the return address:

| Payment record | The resident reads |
|---|---|
| `paid`, `authorized` | Betaald |
| `open`, `pending` | Nog niet betaald, with a button to pay |
| `failed`, `canceled`, `expired` | De betaling is mislukt, with a button to pay |
| anything else | We kunnen de betaling nog niet tonen |

## Next step

Declare `portalFee` on one case type, add your provider's host to `paymentHosts`, and send a test request on the portal while logged in.
