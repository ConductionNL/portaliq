---
kind: code
depends_on: [case-actions-sign-a-document]
---

# Proposal: case-actions-row-inputs-and-conditions

## Why

`case-actions-sign-a-document` lets a row carry an endpoint action: the
resident presses a button on one of their own rows and portaliq forwards the
act to the contributing app. Three merged changes in two sibling apps need
more from that button than a plain confirmation, and each names portaliq as
the owner of the missing half.

**Filinq, `signing-field-validation`** (ConductionNL/filinq, open, from filinq
matrix row `sig-field-validation`, demand
<https://kb.validsign.eu/nl/release-notes/release-notes-2026-09-23>). A signer
fills in fields the initiator placed on the document, such as an IBAN, and
filinq checks each value before it writes the signature. Its cross-app
dependency, verbatim:

> **portaliq**: the portal sign row action must collect the field values filinq declares for the signer and send them with the sign act. Today a row action carries no inputs.

Its design names the consequence: "Until portaliq's row action collects
inputs, an external signer cannot sign a request with required fields."

**Shillinq, `sales-cancellation`** (ConductionNL/shillinq, open, from shillinq
matrix rows `sal-churn-reason` and `sal-withdrawal-button`). A customer
cancels their own subscription from the portal and is asked why, and a
consumer who booked online gets the withdrawal button EU consumer law
requires (article 11a of Directive 2011/83/EU, applicable from 19 June 2026).
Its cross-project dependency, verbatim:

> portaliq: renders the `cancel-subscription` and `withdraw` actions shillinq contributes, with the withdrawal button labelled as article 11a requires, and serves a guest page for a signed withdrawal link so a consumer without a portal account can withdraw.

Its requirement REQ-SCX-003 asks the cancel action to "ask for a reason from
the fixed list without requiring one", and REQ-SCX-005 says that when an
appointment can no longer be withdrawn "the portal SHALL show that reason".

What portaliq does today, read at `4f460b3`:

- `src/portal/App.jsx:268` `onAction` posts `'{}'` to the action forward and
  discards the answer, so an endpoint action with `fields` never collects
  them. `src/portal/components/ActionFieldsForm.jsx` renders labelled inputs
  for an action's `fields` and is imported by nothing.
- `src/portal/components/PageView.jsx:380` passes only `type: update` row
  actions to `CollectionTable`; `case-actions-sign-a-document` T06 adds
  endpoint row actions with a plain confirmation.
- The contribution contract has no way to offer a row action on some rows
  and not others. Filinq's `signing-accept-only-recipient` asks this as an
  open question ("Can the portal contract show a row action only on rows
  where a field matches?").

Decision `build` in the owner-moves pass of 2026-09-28 for each half: each is
a half that a merged sibling change depends on. The guest page for a signed
link is the other half `sales-cancellation` asks for; it is
`identity-guest-page-for-signed-links`.

## What changes

- **A row action asks for what it declares.** An endpoint row action with a
  `fields` whitelist opens a form of those fields, shaped by its
  `fieldConfigs` and static `optionsProviders`, before it forwards. An
  optional field can be left empty.
- **A row action asks for what the row declares.** An endpoint row action
  may name a row field that lists inputs for that one row (name, label,
  required, type). The dialog shows those inputs; the server accepts values
  only for the inputs the row it read declares.
- **Refusals land on the field.** A 422 answer that names inputs is shown
  under each named input, and the dialog stays open.
- **The answer is shown.** A 2xx answer's `message` is shown to the resident,
  so "Your subscription ends on 31 October 2026" reaches them.
- **An action can be offered on some rows only.** An endpoint row action may
  declare the row field that says whether it is available and the row field
  that says why not. Where it is not available, the button is absent, the
  reason is shown, and the server refuses a forward on that row.
- **The label is the contributing app's.** The button and the confirmation
  step use the label and the confirmation text the contribution declares,
  unchanged, so "Withdraw from contract here" reads as the law requires.

## Halves this closes

| Requesting repo | Requesting change | Half |
|---|---|---|
| ConductionNL/filinq | `signing-field-validation` | the sign row action collects the field values filinq declares for the signer |
| ConductionNL/shillinq | `sales-cancellation` | the `cancel-subscription` action with an optional reason from a fixed list, and its outcome shown |
| ConductionNL/shillinq | `sales-cancellation` | the `withdraw` action with its statutory label and a confirmation step, offered only where the booking can still be withdrawn, with the reason shown otherwise |

No portaliq matrix row carries these halves.

## Existing work it builds on

- `case-actions-sign-a-document` (open): endpoint row actions, the row-scoped
  forward `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`
  and its `rowField` stamp.
- `contribution-manifest-v3` (open change): the `fields`, `fieldConfigs`
  and `optionsProviders` vocabulary the normalisers already enforce.

## Out of scope

- Checking what a value means (an IBAN, a date in range). The contributing
  app checks; portaliq enforces only that a required input is not empty.
- Inputs on page-level actions. `case-actions-sign-a-document` T09 shows
  their result; collecting their fields is a later change.
- File inputs on a row action.
