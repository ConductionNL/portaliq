---
kind: code
---

# Proposal: contribution-pay-screen

## Summary

A guardian sees their school contributions in the portal and pays one from its
row. The contributions are shillinq's `ARInvoice` rows, raised through
`POST /apps/shillinq/api/contributions/raise` (shillinq #1704) and contributed
to the `parent` audience. The portal shows the voluntary notice on a voluntary
contribution, asks the guardian to confirm, forwards shillinq's existing `pay`
action for that one row, and sends the browser to the checkout shillinq
returns. Portaliq holds no amount, no invoice state and no payment provider.

## Motivation

Decision D30 (learniq round 1 decisions, 27 September): "The portaliq pay
screen for a school contribution is built now on shillinq's contribution
invoices." It completes the chain of D12 and D19: learniq and portaliq raise a
contribution, shillinq invoices it, the guardian pays it from the portal.

Measured on portaliq `development` `db4a6cf` against shillinq `development`
`2a9d5876e`:

- Shillinq's `parent` manifest (`lib/Portal/PortalContributionProvider.php`,
  `parentManifest()`) declares `salesInvoices` and `paymentRequests` with
  `rowAction: 'pay'`, and one action `pay` of `type: endpoint-forward`.
- Portaliq never renders it. `CollectionConfigNormaliser::resolveRowActions()`
  keeps a `rowActions` entry only when it names a `type: update` action, and it
  never reads the singular `rowAction` key at all. `PageView.jsx` hands only
  update actions to the table. The guardian sees a list with no pay button.
- `App.jsx` `onAction()` posts `'{}'` to the action forward and discards the
  response ("result UI is a follow-up"). No row id reaches shillinq, and a
  returned `checkoutUrl` is thrown away.
- Shillinq writes the voluntary text into `ARInvoice.invoiceNote` only on a
  voluntary contribution (`ContributionInvoiceBuilder`, line 291). The portal
  shows it as one more `invoiceNote:` line in the detail list, with the raw
  field name as its label. The Wet vrijwillige ouderbijdrage asks that a
  guardian sees it is voluntary before paying.

The open change `case-actions-sign-a-document` designs the same missing piece
for filinq's sign action (its D1 and D2: an endpoint action as a row action, and
a row-scoped forward that proves the row first). This change builds those two
decisions once, in its words, so the signing change keeps only its dialog and
its assertion claim.

## Affected Projects

- [x] Project: `portaliq`: endpoint row actions, the row-scoped forward, the
  notice field, the confirm step and the checkout redirect.
- [ ] Project: `shillinq`: follow-up only, not changed here. Its `pay` action
  must declare `rowField: invoiceId` for the button to appear (see Cross-Project
  Dependencies).

## Scope

### In Scope

- A collection's `rowActions` may name an endpoint action. An entry is a string
  id or an object with an `id`. The singular `rowAction` string is read as one
  more entry.
- An endpoint row action declares `rowField`: the body key the portal stamps
  the proven row's id under. Without it the action is not offered on rows.
- An endpoint row action may declare `rowWhen` (`{field, in}`): it is offered,
  and forwarded, only for a row whose field holds one of the listed values.
- A collection may declare `noticeField`: a row field whose text the portal
  shows as a notice on the detail card and in the confirm step.
- A row-scoped forward route that reads the row under the subject's own scope
  before it forwards, and never relays a raw client body.
- The portal SPA: a per-row button, a confirm step with the notice, the
  forward, the checkout redirect (https only), and a plain outcome message.
- Docs page for app authors and for the school's operator.

### Out of Scope

- Any payment logic: amounts, invoice states, the provider, the return page.
  Shillinq owns all of it.
- Changing shillinq's manifest. The three keys it needs are listed in the PR as
  a follow-up.
- The signing dialog and the assertion claim of `case-actions-sign-a-document`
  (its D3 and D4).
- A per-organisation list of payment hosts (`intake-pay-on-submit` owns
  `paymentHosts`).

## Approach

A small resolver owns row action resolution. A new controller serves the
row-scoped forward: find the collection, check the row action and trust, read
the row with the collection's scope, check `rowWhen`, stamp `rowField`, audit,
forward, relay. The SPA reads the resolved action to decide the button, and a
pure helper decides the redirect. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Contribution/CollectionConfigNormaliser.php` (delegates to a new
  `RowActionResolver`), a new `lib/Controller/PortalRowActionController.php`,
  one route, `PortalActionForwarder` gains a public endpoint check.
- `src/portal/components/{CollectionTable,PageView}.jsx`, a new `RowActionConfirm.jsx`, a new `src/portal/lib/rowAction.js`,
  `portalApi.forwardRowAction()`, SPA strings in `src/portal/i18n/`.
- No register or schema change.

## Cross-Project Dependencies

- Consumes shillinq's published `parent` manifest and its
  `portal-payment-initiation` receiver (`POST /apps/shillinq/api/portal/payments/initiate`,
  body `{invoiceId}`, answer `{checkoutUrl}`, 503 `deferred`, 403 uniform).
- Needs one shillinq follow-up to switch the button on: `rowField: 'invoiceId'`
  and `rowWhen: {field: 'state', in: ['issued', 'partially-paid', 'overdue']}`
  on the `pay` action, and `noticeField: 'invoiceNote'` on the parent
  `salesInvoices` collection. Until then portaliq offers no pay button, which is
  today's state.
- Filinq's sign and decline row actions resolve under the same rule once filinq
  declares `rowField`.

## Risks

### Risk 1: The button stays dark until shillinq declares rowField
**Severity:** Medium. **Mitigation:** Named in the PR body and the lane log with
the exact three keys. Portaliq cannot guess the body key without shillinq
knowledge, and a wrong guess would show a button that always fails.

### Risk 2: A checkout redirect is an open redirect
**Severity:** Medium. **Mitigation:** The SPA follows only an absolute `https:`
URL from a 2xx answer of the row-scoped forward. The endpoint is instance-local
by contract, so only a contributing app can name it.

### Risk 3: Two open changes modify the same requirement
**Severity:** Low. **Mitigation:** The delta of "Server-enforced status
transitions" here is a superset of the one in `case-actions-sign-a-document`.
Design names which of its decisions landed here.

## Rollback Strategy

Revert the PR. The route, the resolver and the SPA pieces are additive. A
manifest that declares the new keys falls back to today's behaviour: no row
button, notice shown as a plain detail line.
