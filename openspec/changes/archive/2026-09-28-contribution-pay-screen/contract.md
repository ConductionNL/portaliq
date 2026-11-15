# Contract: contribution-pay-screen

Version 1, 2026-09-27. Portaliq owns this contract: the manifest keys a leaf
app declares to offer an endpoint action on a row, and the forward that runs
it. Leaf apps depend on it duck-typed, as on the rest of contract v2.

## Consumers

- `shillinq`: the `pay` action on the `parent` (and `customer`) manifest.
  Needs `rowField: invoiceId` to be offered (follow-up, see the end).
- `filinq`: the `sign` and `decline` row actions of the `signer` manifest,
  once `case-actions-sign-a-document` lands its dialog and filinq declares
  `rowField: signingRequestId`.
- The portal SPA (`src/portal/`): the only caller of the forward route.

## Manifest keys

### On an endpoint action

| Key | Required | Meaning |
|---|---|---|
| `rowField` | yes, to be a row action | The body key the portal stamps the proven row's id under. `^[a-zA-Z][a-zA-Z0-9_]*$`. |
| `rowWhen` | no | `{ "field": "state", "in": ["issued", "overdue"] }`. The action is offered, and forwarded, only for a row whose `field` holds one of the values. Malformed means the action is not a row action. |
| `fields` | no | The whitelist of client params forwarded. None declared: the body holds only the stamps. |
| `subjectField`, `scopeClaim` | no | As for any endpoint action (portal-take-assessment): the resolved scope is stamped too. |

An endpoint action is one with a non-empty instance-local `endpoint` whose
`type` is not `create`, `update` or `propose-change`. Shillinq's
`type: endpoint-forward` qualifies.

### On a collection

| Key | Meaning |
|---|---|
| `rowActions` | List of action ids, or objects with an `id`. Update actions and endpoint row actions resolve; anything else is dropped. |
| `rowAction` | One action id. Read as one more `rowActions` entry and then removed. |
| `noticeField` | A row field whose text the portal shows as a notice in the detail card and the confirm step. |

### Example: shillinq's parent collection after the follow-up

```json
{
  "collections": [
    {
      "id": "salesInvoices",
      "register": "shillinq",
      "schema": "ARInvoice",
      "scopeField": "customerId",
      "scopeClaim": "customerMasterId",
      "rowAction": "pay",
      "noticeField": "invoiceNote"
    }
  ],
  "actions": [
    {
      "id": "pay",
      "label": "Pay now",
      "type": "endpoint-forward",
      "endpoint": "/apps/shillinq/api/portal/payments/initiate",
      "method": "POST",
      "minTrust": "low",
      "rowField": "invoiceId",
      "rowWhen": { "field": "state", "in": ["issued", "partially-paid", "overdue"] }
    }
  ]
}
```

## Endpoints

### `POST /apps/portaliq/portal/api/collections/{register}/{schema}/{id}/actions/{actionId}?collection={collectionId}`

**Auth**: the portal bearer (`Authorization: Bearer <portal session>`). The
route is `#[PublicPage]` because portal subjects are not Nextcloud users;
`PortalAuthMiddleware` and the controller both fail closed without a subject.

**Request:** any JSON object. Only the action's `fields` survive; the portal
SPA sends no body.

**Forwarded to the leaf app** (the action's `endpoint`, with the signed
`X-Portal-Subject` assertion, never the client's `Authorization`):
```json
{ "invoiceId": "00000000-0000-0000-0000-000000000010" }
```

**Response:** the leaf app's status and JSON body, relayed. For shillinq's pay:
```json
{ "checkoutUrl": "https://pay.example.nl/checkout/REPLACE_ME" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | No portal subject |
| 403  | Collection not in the subject's aggregate, action not one of its row actions, trust below the collection or the action, endpoint not instance-local, or a declared `subjectField` that does not resolve |
| 404  | The row is not the subject's or does not exist (one answer, no oracle) |
| 409  | `{"error": "not_offered"}`: the row does not match the action's `rowWhen` |
| 502  | Transport failure to the leaf app |
| other | Relayed from the leaf app (shillinq: 403 uniform, 503 `deferred`, 502) |

## What the portal SPA does with the answer

| Answer | Portal |
|---|---|
| 2xx with `redirectUrl` or `checkoutUrl`, absolute `https:` | Sends the browser there. |
| 2xx with any other URL | Stays, says the next page could not be opened. |
| 2xx without a URL | Says it worked, reloads the collection. |
| 403, 404, 409 | Says it can no longer be done for this item, reloads the collection. |
| 502, 503, network error | Says it is not available now. |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 401 | Unauthenticated | No portal subject |
| 403 | Forbidden | See the endpoint table |
| 404 | Not found | Row not in the subject's scope |
| 409 | Not offered | Row outside `rowWhen` |
| 502 | Bad gateway | Transport failure |

## Versioning

Version 1, additive to contract v2. A manifest without the new keys behaves as
before. `rowActions` stays a list of ids after normalisation.

## Breaking Change Policy

Renaming `rowField`, `rowWhen` or `noticeField`, or changing what the forward
stamps, names shillinq and filinq as consumers and lands only after both moved.

## SLA

One aggregate build, one scoped read and one forward per call, the same cost as
a single-object read plus an action forward. Rate limited to 20 calls per
minute per client, as a payment start is.

## Shillinq follow-up (not in this change)

In `lib/Portal/PortalContributionProvider.php`: add `rowField` and `rowWhen`
to the `pay` action, and `noticeField: 'invoiceNote'` to the parent
`salesInvoices` collection (in `forParents()`). Drop `rowAction` from
`paymentRequests`: its row id is a payment request, not an invoice, and its
states never match `rowWhen`, so it would never show anyway.
