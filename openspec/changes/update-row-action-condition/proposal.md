---
kind: code
---

# Proposal: update-row-action-condition

## Why

Ruben reviewed the primary-school parent portal (2026-10-02). On the parent
site, the page "Uw gesprekstijden" offers "Deze tijd annuleren" on every row:
on a booked time, but also on a finished, cancelled or declined one. The
button is learniq's `cancelConferenceTime`, a `type: update` row action
(learniq #1614). Learniq's ConferenceSlotBookingSync already refuses a cancel
on a time that cannot be cancelled, so pressing it only produces an error.

Portaliq has a row condition, `rowWhen: {field, in}`, but only for endpoint row
actions (contribution-pay-screen): `offersRowAction()` returns true for every
`type: update` action, so a leaf app has no way to say on which rows an update
action applies.

## What changes

- **One condition for every row action.** `rowWhen` (`{field, in}`, the same
  shape the endpoint row actions and attached actions use) now also decides on
  which rows a `type: update` row action is shown. Without `rowWhen` an update
  action stays on every row, as today.
- **The normaliser checks it.** `RowWhenNormaliser` runs on every contribution
  after the manifest normaliser. An unknown operator inside `rowWhen` (anything
  other than `field` and `in`) is dropped and logged as a warning, like a
  dropped notification rule. A malformed `rowWhen` on an update action
  (no field name, an empty or nested `in`) is dropped whole and logged, so the
  action falls back to every row. An endpoint action keeps its own rule: a
  malformed `rowWhen` keeps it from resolving as a row action.
- **Presentation, never authorisation.** For an update action the condition
  only hides a button. The update itself is still decided by the leaf app's
  lifecycle and listeners (for the cancel, ConferenceSlotBookingSync), so a
  request sent past the screen is refused there, whatever `rowWhen` says.

## Not changed

- The endpoint row action's `rowWhen` keeps its server check (409
  `not_offered`) and its rule for a malformed value.
- No new operators. A booking window ("until the round closes") is not
  expressible as `{field, in}` on the slot row; the leaf app keeps refusing a
  late cancel.
