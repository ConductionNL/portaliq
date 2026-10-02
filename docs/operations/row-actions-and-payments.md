---
title: Row actions and paying from the portal
sidebar_label: Row actions and payments
---

# Row actions and paying from the portal

A parent opens their school contributions and presses "Pay now" on one of
them. A signer presses "Sign" on one document. Both are row actions: a button
on one row of a portal list that runs an action of the app the row belongs to.

Portaliq shows the button, asks the person to confirm and forwards the action
for that one row. The app decides the rest. For a payment that means shillinq
finds the invoice, creates the payment and returns the checkout page.

## For a parent

1. Log in to the portal and open **My contributions**.
2. A contribution you can still pay has a **Pay now** button.
3. Press it. You see what you are about to do. A voluntary contribution says
   so here, and your child takes part whether you pay or not.
4. Press **Continue**. You go to the payment page of the school's payment
   provider. Afterwards you land where the school set it up, usually back in
   the portal.

A paid contribution has no button. When online payment is switched off at the
school, you read that paying is not available right now.

## For the school's operator

Paying from the portal needs three things in shillinq:

- a bound payment provider (without one, the portal says paying is not
  available right now);
- the page the parent returns to after paying:
  `occ config:app:set shillinq portal_payment_redirect_url --value 'https://portal.example.nl/'`;
- parents signed in with the `parent` audience, linked to their customer
  record by the contribution raise.

Portaliq needs no setting of its own.

## For an app author

Declare the action once in your manifest's `actions`, and name it on the
collection whose rows offer it.

```php
'collections' => [
	[
		'id' => 'salesInvoices',
		'register' => 'shillinq',
		'schema' => 'ARInvoice',
		'scopeField' => 'customerId',
		'scopeClaim' => 'customerMasterId',
		'rowActions' => ['pay'],
		'noticeField' => 'invoiceNote',
	],
],
'actions' => [
	[
		'id' => 'pay',
		'label' => 'Pay now',
		'type' => 'endpoint-forward',
		'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
		'method' => 'POST',
		'rowField' => 'invoiceId',
		'rowWhen' => ['field' => 'state', 'in' => ['issued', 'partially-paid', 'overdue']],
	],
],
```

| Key | Where | What it does |
| --- | --- | --- |
| `rowActions` | collection | The actions a row offers. A string id, or an object with an `id`. The single `rowAction` string works too. |
| `rowField` | action | Required. The body key the portal puts the row's id under. Without it the button does not appear. |
| `rowWhen` | action | Optional. Only rows whose `field` holds one of the values in `in` get the button, and the portal refuses the others. |
| `fields` | action | Optional. The request params the portal passes on. Without it, only the row id is sent. |
| `noticeField` | collection | Optional. A row field whose text the portal shows on the card and in the confirm step. |

### A condition on a `type: update` row action

A `type: update` row action, such as a cancel, may declare the same `rowWhen`.
The site then shows its button only on the rows whose `field` holds one of the
values in `in`; without `rowWhen` it shows on every row.

```php
'rowWhen' => ['field' => 'lifecycle', 'in' => ['booked', 'acknowledged']],
```

For an update action the condition only hides the button. The portal does not
refuse the update on it: your app's lifecycle and listeners keep deciding
whether the transition is allowed, so refuse it there too. A key other than
`field` and `in` is dropped, and a malformed condition is dropped whole, both
with a warning in the Nextcloud log (`Portaliq: row condition dropped`).

### What your endpoint receives

A `POST` to your `endpoint` with the signed `X-Portal-Subject` assertion and a
JSON body holding the row id under `rowField`:

```json
{ "invoiceId": "00000000-0000-0000-0000-000000000010" }
```

The portal read that row under the collection's own scope before it sent
anything, so the id is one the person may see. Check ownership again on your
side anyway: the assertion tells you who they are.

### What the portal does with your answer

| Your answer | The portal |
| --- | --- |
| 2xx with `redirectUrl` or `checkoutUrl`, an absolute `https:` URL | Sends the browser there. |
| 2xx with any other URL | Stays, and says the next page could not be opened. |
| 2xx without a URL | Says it worked and reloads the list. |
| 403, 404 or 409 | Says it can no longer be done for this item. |
| 502, 503 | Says it is not available right now. |

### The route

`POST /apps/portaliq/portal/api/collections/{register}/{schema}/{id}/actions/{actionId}?collection={collectionId}`

It answers 401 without a portal session, 403 when the collection does not
offer the action or the trust level is too low, 404 for a row the person does
not own, 409 for a row outside `rowWhen`, and 502 when your app cannot be
reached. Anything else is your app's own answer.
