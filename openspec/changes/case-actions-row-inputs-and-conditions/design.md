# Design: case-actions-row-inputs-and-conditions

Read at portaliq `development` `4f460b3`, and at filinq and shillinq
`development` for the declaring side. Builds on the row-scoped forward of
`case-actions-sign-a-document` (D1, D2), which is not yet built.

## Where it sits today

- `lib/Contribution/ActionConfigNormaliser.php:102` `normaliseActions()`
  keeps an action's `fields` as a string whitelist (:110) and its
  `fieldConfigs` only for whitelisted fields (:229). The options of a field
  come from `ActionOptionsNormaliser` (`static` and `collection` kinds).
- `lib/Contribution/CollectionConfigNormaliser.php:109` `resolveRowActions()`
  keeps only string ids of `type: update` actions today;
  `case-actions-sign-a-document` T02 widens it to endpoint actions with a
  `rowField`.
- `src/portal/components/SchemaForm.jsx` renders an action's whitelisted
  `fields` with `fieldConfigs` and `optionsProviders` for create actions.
  `src/portal/components/ActionFieldsForm.jsx` renders plain text inputs for
  a `fields` list and has no caller.
- `src/portal/components/CollectionTable.jsx:74` renders the row buttons it
  is given; `PageView.jsx:380` gives it only `type: update` actions.
- `lib/Controller/ContributionController.php:1437` `action()` rebuilds the
  forwarded body from the `fields` whitelist and relays the target's status
  and body (:1482-1505).

What the declaring apps send, read on their `development`:

- filinq `signing-field-validation` design: the receiver's sign act "takes a
  `fields` object beside `consent` and `signature`", and a wrong value is
  "refused with a message on that field".
- shillinq `sales-cancellation` D3: `cancel-subscription` and `withdraw` are
  `endpoint-forward` actions; the label "Withdraw from contract here" and
  its Dutch "Herroep de overeenkomst hier" come from shillinq; D5: the
  withdrawable answer "carries the reason when false".

## D1. Declared fields on a row action

An endpoint row action that declares `fields` opens a dialog with those
fields before it forwards, rendered by the field renderer of `SchemaForm.jsx`
(extracted into a shared `ActionFields.jsx`, which `SchemaForm` then uses
too; `ActionFieldsForm.jsx` is removed). `required` comes from `fieldConfigs`;
a field without it may be left empty and is then sent as absent. The server
path is the one `action()` already has: the body is rebuilt from the
whitelist, then `case-actions-sign-a-document` stamps the `rowField`.

## D2. Inputs the row declares

An endpoint row action may declare
`rowInputs: { from: "<row field>", into: "<body key>" }`. The row field holds
a list of `{ name, label, required, type }` with `type` `text` or `date`.
The normaliser keeps `rowInputs` only when `from` is a field the collection
projects and `into` is a plain key not in the action's `fields`, and drops it
otherwise.

The dialog renders one input per descriptor of the selected row. The row
forward reads the row again (it already does, to prove it is the subject's)
and builds `body[into]` from the submitted values for exactly the names that
row declares, dropping any other name and refusing with 422 when a
`required` input is empty. The browser therefore cannot send a value for an
input the row does not ask for.

Alternative considered: static `fields` on filinq's sign action. Rejected:
each document carries its own fields, so the inputs belong to the row, not
to the action.

## D3. The answer reaches the resident

The row forward already relays status and body. The dialog:

- on 2xx shows `body.message` when it is a string, and otherwise the
  contribution's `successText` or "Done.", then reloads the collection;
- on 422 with `body.errors` as an object keyed by input or field name, shows
  each message under its input and keeps the dialog open;
- on any other refusal shows `body.message` or "This could not be done." and
  keeps the dialog open.

## D4. An action offered on some rows only

An endpoint row action may declare `availableWhen: { field, equals }` and
`unavailableReasonField`. The normaliser keeps them only for projected
fields and a scalar `equals`. `CollectionTable` renders the button only on a
row where `row[field] === equals`, and on other rows shows
`row[unavailableReasonField]` as text where the button would be. The row
forward re-checks the condition on the row it read and answers 409 with the
reason when it does not hold, without forwarding.

This also answers filinq's open question in `signing-accept-only-recipient`:
its `accept` action can be offered only on rows whose role is `accept`.

## D5. Labels and confirmation are the contribution's

The button text is the action's `label` and the confirmation step shows the
action's `confirmText` when declared, else "{label}?" and the action label as
the confirm button. Neither is rewritten or translated by portaliq: the
contributing app supplies both languages through its own l10n, as it does for
every label. This is what lets shillinq meet the wording article 11a asks for.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `rowInputs`, `availableWhen`, `confirmText`, `successText` | Declarative, contribution manifest, normalised fail closed | Data the contributing app declares. |
| Building the body from the row's own inputs and re-checking availability | Imperative, the row-scoped forward | A check against the row the server read. |

## Risks

- **A row that lies about its inputs.** The inputs come from the contributing
  app's own row, read under the resident's scope; the contributing app still
  validates every value it receives.
- **Availability decided twice.** The button's absence is a convenience; the
  forward's re-check is the rule. Both read the same row field.

## What it deliberately does not do

- It does not collect files on a row action.
- It does not change `onAction` for page-level buttons beyond what
  `case-actions-sign-a-document` T09 does.
