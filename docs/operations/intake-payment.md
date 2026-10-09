---
sidebar_position: 40
---

# Paying a fee when you submit a request

Some requests cost money. Portaliq shows the fee, sends the resident to the
payment page and tells them afterwards whether the payment went through.
Portaliq creates no payment itself and stores no card or bank data.

## For case app authors

Declare the fee on the case type, in `portalFee`:

| Key | What it holds |
|---|---|
| `amount` | The fee as a decimal with a point, such as `45.00`. |
| `currency` | The three-letter code, such as `EUR`. |
| `description` | A short line the payment page shows. |
| `payAction` | The id of an endpoint action in your contribution for the `client` audience. |

The pay action receives `{reference, amount, currency, description, returnUrl}`.
The amount is the declared one: whatever a browser sends is ignored. Answer
with `{checkoutUrl, paymentIntentId}`. Create the payment through integriq's
payment connector, and use the `returnUrl` as the address the resident returns
on.

Portaliq reads the state of the payment from integriq's `payment_intent`
object (register `openconnector`) by the `paymentIntentId` you returned. It
never reads a status from the address the resident comes back on.

A request with a fee needs a signed-in resident at `substantial` trust, and
says so before the first question.

## For administrators

A payment page is only followed when its address is `https` and its host is in
the portal's **Payment hosts**. The list is empty by default, so nothing
redirects until you name the provider's host, such as `www.mollie.com`. When a
provider changes its checkout host, paying stops with "U kunt nu niet betalen"
until you update the list.
