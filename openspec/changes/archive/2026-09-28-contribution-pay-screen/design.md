# Design: contribution-pay-screen

Read at portaliq `development` `db4a6cf` and shillinq `development`
`2a9d5876e` (shillinq #1704 merged).

## Architecture overview

```
guardian (audience parent)
  -> portal SPA: shillinq's "School contributions" page, salesInvoices table
  -> row button "Pay now" (action pay, offered by rowWhen)
  -> RowActionConfirm: label + notice (noticeField = invoiceNote)
  -> POST /portal/api/collections/shillinq/ARInvoice/{id}/actions/pay?collection=salesInvoices
       PortalRowActionController::forward()
         subject -> collection in aggregate -> pay in its rowActions
         -> trust (collection + action) -> endpoint is instance-local
         -> PortalObjectReader::readObject() with the collection's scope  (404)
         -> rowWhen on the proven row                                     (409)
         -> body = fields whitelist + {invoiceId: row id} (+ subjectField)
         -> audit "forward" -> PortalActionForwarder::forward() -> relay
  -> shillinq PortalPaymentInitiationController: {checkoutUrl}
  -> SPA: https only -> window.location.assign(checkoutUrl)
```

Portaliq renders, proves the row, forwards and follows the answer. Which
invoice is payable, the amount, the payment request and the provider stay in
shillinq.

## Where the pay screen is lost today

1. `lib/Contribution/CollectionConfigNormaliser.php` `resolveRowActions()`
   keeps a `rowActions` string only when it names a `type: update` action.
   Shillinq declares `rowAction: 'pay'` (singular), which nothing reads, and
   `pay` is `type: endpoint-forward`.
2. `src/portal/components/PageView.jsx` passes only update actions to
   `CollectionTable`; `App.jsx` `onRowAction()` can only PATCH with `{}`.
3. `App.jsx` `onAction()` posts `'{}'` and drops the answer, so a
   `checkoutUrl` never reaches the browser.
4. The detail card lists `invoiceNote` like any other field, under its raw
   name.

## Decisions

### D1. An endpoint action can be a row action (from case-actions-sign-a-document D1)

A new `lib/Contribution/RowActionResolver.php` owns the resolution;
`CollectionConfigNormaliser::resolveRowActions()` delegates to it, so the
existing call in `PortalManifestNormaliser::normalise()` is unchanged.

- Entries: a string id, or an object with a string `id` (reduced to the id).
  The singular `rowAction` string is appended as one more entry and the key is
  removed. Duplicates collapse.
- An entry resolves when it names a `type: update` action, or an endpoint row
  action: non-empty instance-local `endpoint`, `type` not `create`, `update` or
  `propose-change`, a well-formed `rowField`, and `rowWhen` absent or
  well-formed.
- The output stays a list of ids. Every caller of `rowActions` (the SPA, the
  existing tests) keeps working, and the SPA reads the kind off the resolved
  action (`type: update`, else endpoint). The endpoint, method and `minTrust`
  always come from the top-level action that survived `normaliseActions()`,
  never from an inline object, so an inline object cannot smuggle an endpoint
  past the SSRF check.

**Why rowField is required, not defaulted.** The only producer today
(shillinq) reads `invoiceId`; filinq reads `signingRequestId`. Any default
portaliq picks is a guess about a leaf app, and a wrong guess shows a button
that always fails. Without `rowField` the action is not offered, which is
today's state.

Alternative considered: send the row id under a fixed key (`id`). Rejected:
neither receiver reads it, and changing two receivers to fit portaliq is the
wrong direction for a contract portaliq owns.

### D2. A row-scoped forward proves the row first (from case-actions-sign-a-document D2)

New `lib/Controller/PortalRowActionController.php`, route
`portalRowAction#forward`, `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`,
registered beside `portalFieldFile#upload` and before the catch-all. A separate
controller rather than one more method on the 1,615-line
`ContributionController`, the same call `assignment-portal-file-upload` made.

Order, each step refusing before the next runs:

1. Subject from the bearer, else 401.
2. The collection by register, schema and `?collection=` in the subject's own
   aggregate, and `actionId` among its normalised `rowActions`, and an endpoint
   row action of the same contribution. Else 403.
3. `minTrust` of the collection and of the action, and
   `PortalActionForwarder::isForwardable()` (instance-local path, allowed
   method). Else 403.
4. `PortalObjectReader::readObject()` with the collection's `scopeField`,
   `scopeClaim`, `via`, audience and `fields`, exactly as
   `ContributionController::object()` does. Null is one 404.
5. `rowWhen` against the proven row. No match is 409 `not_offered`. A field the
   collection projects away never matches, so it fails closed.
6. Body: the action's `fields` whitelist of the request params, then
   `rowField` = the row id, then a declared `subjectField` = the resolved scope
   (403 when it does not resolve). Never the raw client body.
7. Audit `forward` (register = app, schema = action id, id = row id), forward,
   relay status and JSON; 502 on transport failure.

`PortalActionForwarder` gains `isForwardable(array $action): bool`, the same
endpoint and method rule `ContributionController::isForwardableAction()` holds.
`ContributionController` keeps its private copy in this change: moving it would
change its tests' forwarder double for no gain here. Rate limit 20 per minute,
as shillinq's own pay receiver.

### D3. rowWhen offers an action only where it applies

`{field, in}` on the endpoint action. The SPA hides the button on other rows;
the forward refuses them with 409. Shillinq's receiver already refuses a paid
invoice with a uniform 403, so the server half is defence in depth; the UI half
is what REQ-SPPI-006 asks of portaliq ("a settled/non-payable row MUST NOT
offer it").

### D4. noticeField shows the voluntary text

`noticeField` on the collection, a field name, dropped when malformed by
`RowActionResolver::normaliseNoticeField()`. The detail card shows the value as
a notice above the field list, and the confirm step shows it above the confirm
button. Portaliq does not know the word "voluntary"; shillinq writes
`invoiceNote` only on a voluntary contribution, in the guardian's language.

### D5. The confirm step and the redirect

- `src/portal/components/RowActionConfirm.jsx` (own file): heading = action
  label, the notice when present, "Continue" and "Cancel". Focus moves to the
  heading when it opens. A `role="status"` line carries the outcome.
- `src/portal/lib/rowAction.js` (pure, node-tested): `isEndpointRowAction`,
  `offersRowAction(action, row)`, `redirectTarget(result)` (an absolute
  `https:` URL from a 2xx `redirectUrl` or `checkoutUrl`, else null), and
  `outcomeKey(result)` (one English source string per answer class),
  `rowNotice(collection, row)`, and `runRowAction(api, collection, row, action)`
  which calls the forward and returns `{redirect, messageKey}`.
- `portalApi.forwardRowAction(collection, rowId, actionId)` posts `{}`.
- `PageView.jsx` owns the step, next to the table it belongs to: a row click
  on an endpoint action opens `RowActionConfirm` below that table; an update
  action still goes to `App.jsx` `onRowAction()` and its PATCH, unchanged.
  `CollectionTable` gets an `offers(action, row)` prop so it stays free of
  imports (its keyboard test compiles it on its own). On a redirect target:
  `window.location.assign()`. Otherwise the message, and a reload of the
  collection through the page's existing `onCreated` reload.

Alternative considered: follow any URL the answer carries. Rejected: the
answer is relayed from a leaf app, and `javascript:` or plain `http:` must
never be followed from a portal a guardian trusts. A per-organisation host
list is `intake-pay-on-submit`'s `paymentHosts`, and can tighten this later.

### D6. Nothing about the payment is portaliq's

No amount is read, computed or sent. Shillinq resolves the invoice from the
stamped id and the `customerMasterId` claim it reads itself, mints the payment
request, and returns the checkout. Where the guardian lands afterwards is
shillinq's `portal_payment_redirect_url` app config (the docs page tells the operator
to point it at the portal).

## Relation to case-actions-sign-a-document

This change builds that change's D1 and D2 in its own words. What stays there:
D3 (the scope claim inside the assertion), D4 (the signing and decline
dialogs, which can replace `RowActionConfirm` for `sign` and `decline`), and
filinq's `rowField`. Its delta of "Server-enforced status transitions" is a
subset of the one here; whichever archives second reconciles the text.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Which row offers the action (`rowWhen`) | Declarative, manifest data | Pure data the leaf app declares; portaliq only evaluates it. |
| The notice (`noticeField`) | Declarative, manifest data | Presentation key. |
| The row-scoped forward | Imperative controller | An auth edge: scoped read, signed assertion, outbound call. ADR-031's external-integration exception, as the existing A6 forward. |

No lifecycle, aggregation, calculation or notification is added.

## API design

See contract.md. One route, `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`.

## Database changes

None. No register, schema or migration change.

## Nextcloud integration

- Controllers: `PortalRowActionController` (`#[PublicPage]`, `#[NoCSRFRequired]`,
  `#[AnonRateLimit(limit: 20, period: 60)]`, `PortalProtected`).
- Services: `PortalContributionRegistry`, `PortalSessionService`,
  `PortalObjectReader`, `PortalActionForwarder`, `AuditTrailService`.
- Events/Hooks: none.

## Security considerations

- The browser names the row only in the path; the id reaching the leaf app is
  the id of the row portaliq read under the subject's own scope.
- A raw client body is never relayed on this route.
- Trust is re-checked for the collection and the action; the endpoint is
  re-checked as instance-local at forward time.
- 404 for a foreign and a missing row alike.
- The redirect follows `https:` only, from a 2xx answer.
- The audit entry records the forward before its outcome, as `action()` does.

## NL Design System

Plain portal markup with the portal's existing classes (`portaliq-cta`,
`portaliq-badge`); the notice uses a new `portaliq-notice` class with the
theme's custom properties. No colour is hardcoded.

## File structure

```
lib/Contribution/RowActionResolver.php          new
lib/Contribution/CollectionConfigNormaliser.php delegates rowActions, noticeField
lib/Controller/PortalRowActionController.php    new
lib/Service/PortalActionForwarder.php           isForwardable()
appinfo/routes.php                              one route
src/portal/lib/rowAction.js                     new, pure
src/portal/lib/portalApi.js                     forwardRowAction()
src/portal/components/RowActionConfirm.jsx      new
src/portal/components/CollectionTable.jsx       offers(action, row) per row
src/portal/components/PageView.jsx              both kinds, the confirm step, notice
src/portal/i18n/{en,nl}.json                    strings
src/portal/theme.css                            .portaliq-notice
docs/operations/row-actions-and-payments.md     new
tests/Unit/Contribution/RowActionResolverTest.php
tests/Unit/Controller/PortalRowActionControllerTest.php
tests/row-action.spec.mjs                       node --test, check:row-action
```

## Seed data

None. This change adds no OpenRegister schema and changes none. The rows it
shows are shillinq's `ARInvoice` and `PaymentRequest`, seeded by shillinq.
Tests build synthetic rows on the nil-UUID pattern.

## Risks / trade-offs

- [The pay button stays dark until shillinq adds `rowField`] → named in the PR
  body and the lane log with the exact keys (contract.md, last section).
- [Two controllers hold the same endpoint rule] → the new one reads it from
  the forwarder; folding `ContributionController` onto it is a one-line
  follow-up once its tests use the real forwarder.
- [propose-change entries are still dropped from `rowActions`] → unchanged
  behaviour, not this change's; noted in the PR body.

## Migration plan

Additive. Deploy, and the buttons appear only for manifests that declare
`rowField`. Rollback is a revert.
